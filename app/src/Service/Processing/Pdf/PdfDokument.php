<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * The object structure of one PDF file, as far as reading its text needs it:
 * every indirect object, the catalog, the pages in order.
 *
 * Objects are found by scanning the file front to back rather than through
 * the cross-reference table: that table is exactly what broken or
 * hand-edited files get wrong, and the scan copes with incremental updates
 * the same way (a later definition of an object number replaces the
 * earlier one). Stream data is skipped by its /Length, so an image stream
 * that happens to contain "12 0 obj" does not produce a phantom object.
 * Objects inside object streams (PDF 1.5) are registered by number and only
 * parsed when asked for.
 */
final class PdfDokument
{
    private const int MAX_REFERENZKETTE = 32;

    /** @var array<int, mixed> object number → parsed value (PdfStream for streams) */
    private array $objekte = [];

    /** @var array<int, int> object number → number of the object stream holding it */
    private array $inObjektStream = [];

    /** @var list<int> numbers of the object streams found by the scan */
    private array $objektStreamNummern = [];

    /** @var array<int, array{string, array<int, int>, int}> stream number → [decoded data, number → offset, First] */
    private array $objektStreams = [];

    /** @var list<array<string, mixed>> the trailer dictionaries, in file order (classic trailers and XRef streams) */
    private array $trailer = [];

    /** Bytes the decoding of streams may still produce (PdfFilter). */
    private int $budget;

    public function __construct(private readonly string $daten, int $budget)
    {
        // The header may follow a few bytes of junk (ISO 32000-1 annex H.3 tolerates up to 1024).
        if (!str_contains(substr($daten, 0, 1024), '%PDF-')) {
            throw new PdfDefekt('Keine PDF-Datei.');
        }
        $this->budget = $budget;
        $this->indizieren();
    }

    /** Whether the file is encrypted (a standard security handler, any key) - its strings cannot be read. */
    public function verschluesselt(): bool
    {
        foreach ($this->trailer as $trailer) {
            if (array_key_exists('Encrypt', $trailer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every page in reading order, with the resources it inherits from the
     * page tree (ISO 32000-1 section 7.7.3.4) - at most $maximal.
     *
     * @return list<array{array<string, mixed>, array<string, mixed>}> [page dictionary, resources]
     */
    public function seiten(int $maximal): array
    {
        $katalog = $this->katalog();
        $seiten = [];
        $besucht = [];
        if ($katalog !== null) {
            $wurzel = $katalog['Pages'] ?? null;
            $this->seitenbaum($wurzel, [], $seiten, $besucht, $maximal, 0);
        }

        if ($seiten === []) {
            // No usable page tree: every page object in object number order.
            ksort($this->objekte);
            foreach ($this->objekte as $objekt) {
                if (is_array($objekt) && self::typ($objekt) === 'Page') {
                    $resourcen = $this->aufloesen($objekt['Resources'] ?? null);
                    $seiten[] = [$objekt, is_array($resourcen) ? $resourcen : []];
                    if (count($seiten) >= $maximal) {
                        break;
                    }
                }
            }
        }

        return $seiten;
    }

    /** The value behind a reference (following chains of them), anything else as it is. */
    public function aufloesen(mixed $wert): mixed
    {
        for ($i = 0; $wert instanceof PdfRef; $i++) {
            if ($i >= self::MAX_REFERENZKETTE) {
                return null;
            }
            $wert = $this->objekt($wert->nummer);
        }

        return $wert;
    }

    /** The decoded data of a stream, within the remaining budget of the file. */
    public function daten(PdfStream $stream): string
    {
        $roh = substr($this->daten, $stream->start, $stream->laenge);
        $filter = $this->aufloesen($stream->dict['Filter'] ?? null);
        $parameter = $this->aufloesen($stream->dict['DecodeParms'] ?? null);

        $filterListe = [];
        foreach (is_array($filter) ? $filter : [$filter] as $name) {
            $name = $this->aufloesen($name);
            if ($name instanceof PdfName) {
                $filterListe[] = $name->wert;
            }
        }
        $parameterListe = [];
        foreach (is_array($parameter) && array_is_list($parameter) ? $parameter : [$parameter] as $eintrag) {
            $eintrag = $this->aufloesen($eintrag);
            $parameterListe[] = is_array($eintrag) ? $eintrag : [];
        }

        $ergebnis = PdfFilter::dekodieren($roh, $filterListe, $parameterListe, $this->budget);
        $this->budget -= strlen($ergebnis);

        return $ergebnis;
    }

    /**
     * @param array<string, mixed> $dict
     */
    public static function typ(array $dict): ?string
    {
        $typ = $dict['Type'] ?? null;

        return $typ instanceof PdfName ? $typ->wert : null;
    }

    private function objekt(int $nummer): mixed
    {
        if (array_key_exists($nummer, $this->objekte)) {
            return $this->objekte[$nummer];
        }
        if (!isset($this->inObjektStream[$nummer])) {
            return null;
        }

        $streamNummer = $this->inObjektStream[$nummer];
        // Parsed once; a failing object stream is remembered as empty.
        $this->objekte[$nummer] = null;
        try {
            $this->objekte[$nummer] = $this->ausObjektStream($streamNummer, $nummer);
        } catch (PdfDefekt) {
            // The object stays null - like a reference to a missing object.
        }

        return $this->objekte[$nummer];
    }

    private function ausObjektStream(int $streamNummer, int $nummer): mixed
    {
        [$daten, $offsets, $erstes] = $this->objektStreamLaden($streamNummer);
        if (!isset($offsets[$nummer])) {
            throw new PdfDefekt('Objekt fehlt im Objekt-Stream.');
        }

        return new PdfLexer($daten, $erstes + $offsets[$nummer])->wert();
    }

    /**
     * Decodes an object stream and reads its header (ISO 32000-1 section
     * 7.5.7: N pairs "object number, offset", the objects from /First on) -
     * once per stream.
     *
     * @return array{string, array<int, int>, int}
     */
    private function objektStreamLaden(int $streamNummer): array
    {
        if (isset($this->objektStreams[$streamNummer])) {
            return $this->objektStreams[$streamNummer];
        }

        $stream = $this->objekte[$streamNummer] ?? null;
        if (!$stream instanceof PdfStream) {
            throw new PdfDefekt('Objekt-Stream fehlt.');
        }
        $daten = $this->daten($stream);
        $anzahl = is_int($stream->dict['N'] ?? null) ? $stream->dict['N'] : 0;
        $erstes = is_int($stream->dict['First'] ?? null) ? $stream->dict['First'] : 0;

        $kopf = new PdfLexer(substr($daten, 0, $erstes));
        $offsets = [];
        for ($i = 0; $i < $anzahl && !$kopf->amEnde(); $i++) {
            $objektNummer = $kopf->wert(false);
            $offset = $kopf->wert(false);
            if (is_int($objektNummer) && is_int($offset)) {
                $offsets[$objektNummer] = $offset;
            }
        }

        return $this->objektStreams[$streamNummer] = [$daten, $offsets, $erstes];
    }

    /**
     * The front-to-back scan of the class docblock.
     */
    private function indizieren(): void
    {
        $pos = 0;
        $muster = '/(?<![0-9])(\d{1,10})[\x00\t\n\x0C\r ]+\d{1,5}[\x00\t\n\x0C\r ]+obj(?=[\x00\t\n\x0C\r ()<>\[\]{}\/%])/';
        while (preg_match($muster, $this->daten, $treffer, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $nummer = (int) $treffer[1][0];
            $koerper = $treffer[0][1] + strlen($treffer[0][0]);
            $pos = $koerper;

            try {
                $lexer = new PdfLexer($this->daten, $koerper);
                $wert = $lexer->wert();
                if (is_array($wert) && !array_is_list($wert) && $lexer->folgt('stream')) {
                    $stream = $this->stream($wert, $lexer->pos + 6);
                    $this->objekte[$nummer] = $stream;
                    $pos = $stream->start + $stream->laenge;
                    $this->streamRegistrieren($nummer, $stream);
                } else {
                    $this->objekte[$nummer] = $wert;
                    $pos = $lexer->pos;
                }
            } catch (PdfDefekt) {
                // An object this reader cannot parse is as good as missing;
                // the scan continues behind its header.
            }
        }

        $this->trailerSuchen();
        $this->objektStreamsRegistrieren();
    }

    /**
     * @param array<string, mixed> $dict
     */
    private function stream(array $dict, int $nachKeyword): PdfStream
    {
        // The keyword is followed by CRLF or LF (a lone CR happens too).
        $start = $nachKeyword;
        if (($this->daten[$start] ?? '') === "\r") {
            $start++;
        }
        if (($this->daten[$start] ?? '') === "\n") {
            $start++;
        }

        $laenge = $dict['Length'] ?? null;
        if (is_int($laenge) && $laenge >= 0 && $start + $laenge <= strlen($this->daten)
            && preg_match('/\G[\x00\t\n\x0C\r ]*endstream/', $this->daten, $treffer, 0, $start + $laenge) === 1) {
            return new PdfStream($dict, $start, $laenge);
        }

        // /Length is a reference (written after the stream) or wrong: up to
        // the next "endstream", without the end-of-line before it.
        $ende = strpos($this->daten, 'endstream', $start);
        if ($ende === false) {
            throw new PdfDefekt('Stream ohne Ende.');
        }
        $laenge = $ende - $start;
        if ($laenge > 0 && $this->daten[$start + $laenge - 1] === "\n") {
            $laenge--;
        }
        if ($laenge > 0 && $this->daten[$start + $laenge - 1] === "\r") {
            $laenge--;
        }

        return new PdfStream($dict, $start, $laenge);
    }

    private function streamRegistrieren(int $nummer, PdfStream $stream): void
    {
        $typ = self::typ($stream->dict);
        if ($typ === 'XRef') {
            $this->trailer[] = $stream->dict;
        } elseif ($typ === 'ObjStm') {
            $this->objektStreamNummern[] = $nummer;
        }
    }

    private function trailerSuchen(): void
    {
        $pos = 0;
        while (($pos = strpos($this->daten, 'trailer', $pos)) !== false) {
            $pos += 7;
            try {
                $wert = new PdfLexer($this->daten, $pos)->wert();
                if (is_array($wert) && !array_is_list($wert)) {
                    $this->trailer[] = $wert;
                }
            } catch (PdfDefekt) {
                // Not a trailer after all (the word inside other data).
            }
        }
    }

    /**
     * Registers the members of every object stream by number, without
     * parsing them yet. A member already defined directly keeps that
     * definition; an undecodable object stream contributes nothing.
     */
    private function objektStreamsRegistrieren(): void
    {
        foreach ($this->objektStreamNummern as $streamNummer) {
            try {
                $offsets = $this->objektStreamLaden($streamNummer)[1];
            } catch (PdfDefekt) {
                continue;
            }
            foreach (array_keys($offsets) as $mitglied) {
                if (!array_key_exists($mitglied, $this->objekte) && !isset($this->inObjektStream[$mitglied])) {
                    $this->inObjektStream[$mitglied] = $streamNummer;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function katalog(): ?array
    {
        // The last trailer that names a root wins - the newest revision.
        foreach (array_reverse($this->trailer) as $trailer) {
            $katalog = $this->aufloesen($trailer['Root'] ?? null);
            if (is_array($katalog) && !array_is_list($katalog)) {
                return $katalog;
            }
        }

        foreach ($this->objekte as $objekt) {
            if (is_array($objekt) && self::typ($objekt) === 'Catalog') {
                return $objekt;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $resourcen inherited from the parents
     * @param list<array{array<string, mixed>, array<string, mixed>}> $seiten
     * @param array<int, true> $besucht object numbers of nodes seen, against cycles
     */
    private function seitenbaum(mixed $knoten, array $resourcen, array &$seiten, array &$besucht, int $maximal, int $tiefe): void
    {
        if ($knoten instanceof PdfRef) {
            if (isset($besucht[$knoten->nummer])) {
                return;
            }
            $besucht[$knoten->nummer] = true;
        }
        $knoten = $this->aufloesen($knoten);
        if (!is_array($knoten) || array_is_list($knoten) || $tiefe > 64 || count($seiten) >= $maximal) {
            return;
        }

        $eigene = $this->aufloesen($knoten['Resources'] ?? null);
        if (is_array($eigene) && !array_is_list($eigene)) {
            $resourcen = $eigene;
        }

        $kinder = $this->aufloesen($knoten['Kids'] ?? null);
        if (self::typ($knoten) === 'Pages' || (is_array($kinder) && !array_key_exists('Contents', $knoten))) {
            foreach (is_array($kinder) ? $kinder : [] as $kind) {
                $this->seitenbaum($kind, $resourcen, $seiten, $besucht, $maximal, $tiefe + 1);
            }

            return;
        }

        $seiten[] = [$knoten, $resourcen];
    }
}
