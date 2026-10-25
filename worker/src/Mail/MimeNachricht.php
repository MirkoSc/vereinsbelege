<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * Just enough of RFC 5322/2045-2047/2231 to take a receipt mail apart
 * (issue #87, E-54): sender, subject, Message-ID, spam mark and the leaf
 * parts that may be attachments, decoded. Tolerant by design - a broken
 * mail gives fewer attachments, never an exception - and bounded (depth,
 * number of parts).
 *
 * Forwarded mails (`message/rfc822`) are opened: "als Anhang weiterleiten"
 * is how a member passes a supplier's invoice on.
 */
final readonly class MimeNachricht
{
    /** Nesting levels; real mail rarely needs more than three. */
    private const int MAX_TIEFE = 4;

    /** Longest header block read. */
    private const int MAX_KOPF = 256 * 1024;

    private const int MAX_TEILE = 200;

    /**
     * Encoded parts are decoded in pieces of this size, straight from the
     * one mail buffer - no copy of the encoded part (N-16).
     */
    private const int STUECK = 1024 * 1024;

    /**
     * @param list<MimeAnhang> $anhaenge
     */
    private function __construct(
        public string $absenderName,
        public string $absenderAdresse,
        public string $betreff,
        public ?string $messageId,
        public bool $spam,
        public array $anhaenge,
    ) {
    }

    public static function lesen(string $roh): self
    {
        $ende = strlen($roh);
        [$felder, $rumpfStart] = self::kopf($roh, 0, $ende);
        [$name, $adresse] = self::absender($felder['from'][0] ?? '');
        $messageId = self::messageId($felder);

        $anhaenge = [];
        $zaehler = 0;
        self::teil($roh, $felder, $rumpfStart, $ende, 0, $anhaenge, $zaehler);

        return new self(
            absenderName: $name,
            absenderAdresse: $adresse,
            betreff: self::woerter($felder['subject'][0] ?? ''),
            messageId: $messageId,
            spam: self::spam($felder),
            anhaenge: $anhaenge,
        );
    }

    /**
     * The Message-ID of a header block alone (`BODY.PEEK[HEADER.FIELDS
     * (MESSAGE-ID)]`) - the idempotency key of a mail that is not fetched
     * again (issue #243).
     */
    public static function messageIdAusKopf(string $kopf): ?string
    {
        return self::messageId(self::felder(substr($kopf, 0, self::MAX_KOPF)));
    }

    /** @param array<string, list<string>> $felder */
    private static function messageId(array $felder): ?string
    {
        return preg_match('/<([^<>\s]{1,995})>/', $felder['message-id'][0] ?? '', $t) === 1 ? $t[1] : null;
    }

    /**
     * One MIME entity whose body is $roh[$start, $ende). Works on offsets
     * into the one buffer: a nesting level costs no copy of the mail, only
     * the leaves that may be attachments are decoded (review of #87 - a
     * crafted, deeply nested mail must not exhaust the memory).
     *
     * A forwarded mail that is itself base64 or quoted-printable encoded
     * (RFC 2046 forbids it, some clients do it) needs a decoded copy of its
     * own. There is at most one such copy at a time: an encoded forward
     * inside the copy is not opened (N-16) - so the decoded parts stay
     * below three times the mail: the copy (< 1x) plus the leaves (< 1x).
     *
     * @param array<string, list<string>> $felder
     * @param list<MimeAnhang> $anhaenge
     */
    private static function teil(string $roh, array $felder, int $start, int $ende, int $tiefe, array &$anhaenge, int &$zaehler, bool $kopie = false): void
    {
        if (++$zaehler > self::MAX_TEILE || $tiefe > self::MAX_TIEFE) {
            return;
        }
        [$typ, $parameter] = self::wertMitParametern($felder['content-type'][0] ?? 'text/plain');
        $typ = strtolower($typ);
        $kodierung = strtolower(trim($felder['content-transfer-encoding'][0] ?? ''));

        if (str_starts_with($typ, 'multipart/')) {
            $grenze = $parameter['boundary'] ?? '';
            if ($grenze === '' || strlen($grenze) > 200) {
                return;
            }
            foreach (self::teile($roh, $start, $ende, $grenze) as [$s, $e]) {
                [$k, $r] = self::kopf($roh, $s, $e);
                self::teil($roh, $k, $r, $e, $tiefe + 1, $anhaenge, $zaehler, $kopie);
                if ($zaehler > self::MAX_TEILE) {
                    return;
                }
            }

            return;
        }

        if ($typ === 'message/rfc822') {
            if (in_array($kodierung, ['base64', 'quoted-printable'], true)) {
                if ($kopie) {
                    // An encoded forward inside an encoded forward: not opened.
                    return;
                }
                $innen = self::dekodieren($roh, $start, $ende, $kodierung);
                [$k, $r] = self::kopf($innen, 0, strlen($innen));
                self::teil($innen, $k, $r, strlen($innen), $tiefe + 1, $anhaenge, $zaehler, true);
            } else {
                [$k, $r] = self::kopf($roh, $start, $ende);
                self::teil($roh, $k, $r, $ende, $tiefe + 1, $anhaenge, $zaehler, $kopie);
            }

            return;
        }

        [$disposition, $dispParameter] = self::wertMitParametern($felder['content-disposition'][0] ?? '');
        $disposition = strtolower($disposition);
        $name = $dispParameter['filename'] ?? $parameter['name'] ?? '';
        // The text of the mail itself is no attachment - and not decoded.
        if (str_starts_with($typ, 'text/') && $name === '' && $disposition !== 'attachment') {
            return;
        }
        $anhaenge[] = new MimeAnhang(
            name: self::dateiname($name),
            inhalt: self::dekodieren($roh, $start, $ende, $kodierung),
            typ: $typ,
            inline: $disposition !== 'attachment' && isset($felder['content-id']),
        );
    }

    /**
     * The header fields of the entity starting at $start and where its
     * body begins. Header blocks are short; a giant one is cut off.
     *
     * @return array{0: array<string, list<string>>, 1: int}
     */
    private static function kopf(string $roh, int $start, int $ende): array
    {
        while ($start < $ende && ($roh[$start] === "\r" || $roh[$start] === "\n")) {
            ++$start;
        }
        $fenster = min($ende - $start, self::MAX_KOPF);
        $treffer = [];
        foreach (["\r\n\r\n", "\n\n"] as $trenner) {
            $pos = $fenster > 0 ? strpos(substr($roh, $start, $fenster), $trenner) : false;
            if ($pos !== false) {
                $treffer[] = [$pos, strlen($trenner)];
            }
        }
        if ($treffer === []) {
            return [self::felder(substr($roh, $start, $fenster)), $ende];
        }
        usort($treffer, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        [$pos, $laenge] = $treffer[0];

        return [self::felder(substr($roh, $start, $pos)), $start + $pos + $laenge];
    }

    /**
     * Header fields, unfolded, by lower-case name.
     *
     * @return array<string, list<string>>
     */
    private static function felder(string $kopf): array
    {
        $felder = [];
        $kopf = preg_replace('/\r?\n[ \t]+/', ' ', $kopf) ?? '';
        foreach (preg_split('/\r?\n/', $kopf) ?: [] as $zeile) {
            $doppelpunkt = strpos($zeile, ':');
            if ($doppelpunkt === false || $doppelpunkt === 0) {
                continue;
            }
            $felder[strtolower(trim(substr($zeile, 0, $doppelpunkt)))][] = trim(substr($zeile, $doppelpunkt + 1));
        }

        return $felder;
    }

    /**
     * The [start, end) offsets of the parts between the boundaries of
     * $roh[$start, $ende) - found with strpos, nothing copied.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function teile(string $roh, int $start, int $ende, string $grenze): array
    {
        $trenner = '--' . $grenze;
        $teile = [];
        $teilStart = null;
        $pos = $start;
        while ($pos < $ende && count($teile) < self::MAX_TEILE) {
            $fund = strpos($roh, $trenner, $pos);
            if ($fund === false || $fund >= $ende) {
                break;
            }
            // Only at the start of a line.
            if ($fund !== $start && $roh[$fund - 1] !== "\n") {
                $pos = $fund + 1;
                continue;
            }
            $nachher = $fund + strlen($trenner);
            $schluss = substr($roh, $nachher, 2) === '--';
            if ($teilStart !== null) {
                // The line break before a boundary belongs to the boundary.
                $teilEnde = $fund;
                if ($teilEnde > $teilStart && $roh[$teilEnde - 1] === "\n") {
                    --$teilEnde;
                    if ($teilEnde > $teilStart && $roh[$teilEnde - 1] === "\r") {
                        --$teilEnde;
                    }
                }
                $teile[] = [$teilStart, $teilEnde];
            }
            if ($schluss) {
                return $teile;
            }
            $zeilenEnde = strpos($roh, "\n", $nachher);
            if ($zeilenEnde === false || $zeilenEnde >= $ende) {
                return $teile;
            }
            $teilStart = $zeilenEnde + 1;
            $pos = $teilStart;
        }
        if ($teilStart !== null && $teilStart < $ende && count($teile) < self::MAX_TEILE) {
            $teile[] = [$teilStart, $ende];
        }

        return $teile;
    }

    /**
     * Decodes $roh[$start, $ende) piece by piece: only the decoded result
     * and one piece are in memory, never a copy of the encoded part.
     */
    private static function dekodieren(string $roh, int $start, int $ende, string $kodierung): string
    {
        if ($kodierung !== 'base64' && $kodierung !== 'quoted-printable') {
            return substr($roh, $start, $ende - $start);
        }
        $aus = '';
        $rest = '';
        $pos = $start;
        while ($pos < $ende) {
            $stueck = substr($roh, $pos, min(self::STUECK, $ende - $pos));
            if ($kodierung === 'base64') {
                $pos += strlen($stueck);
                // Line breaks, padding and foreign bytes out, then whole
                // groups of four - the rest goes with the next piece.
                $stueck = $rest . (preg_replace('~[^A-Za-z0-9+/]~', '', $stueck) ?? '');
                $nutzbar = strlen($stueck) - strlen($stueck) % 4;
                $aus .= (string) base64_decode(substr($stueck, 0, $nutzbar), false);
                $rest = substr($stueck, $nutzbar);
                continue;
            }
            // Quoted-printable: up to the last line break of the piece, so
            // no `=XX` and no soft line break is cut in two.
            if ($pos + strlen($stueck) < $ende) {
                $zeilenEnde = strrpos($stueck, "\n");
                if ($zeilenEnde !== false) {
                    $stueck = substr($stueck, 0, $zeilenEnde + 1);
                } else {
                    // A line longer than a piece: at least no `=XX` cut in two.
                    $gleich = strrpos(substr($stueck, -2), '=');
                    if ($gleich !== false) {
                        $stueck = substr($stueck, 0, strlen($stueck) - 2 + $gleich);
                    }
                }
            }
            $pos += strlen($stueck);
            $aus .= quoted_printable_decode($stueck);
        }
        if ($rest !== '') {
            $aus .= (string) base64_decode($rest, false);
        }

        return $aus;
    }

    /**
     * `type/sub; a=b; c="d"` with RFC 2231 (`name*=utf-8''…`, `name*0*=`).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function wertMitParametern(string $feld): array
    {
        $teile = [];
        $aktuell = '';
        $inZitat = false;
        $laenge = strlen($feld);
        for ($i = 0; $i < $laenge; ++$i) {
            $z = $feld[$i];
            if ($z === '\\' && $inZitat && $i + 1 < $laenge) {
                $aktuell .= $feld[++$i];
                continue;
            }
            if ($z === '"') {
                $inZitat = !$inZitat;
                continue;
            }
            if ($z === ';' && !$inZitat) {
                $teile[] = $aktuell;
                $aktuell = '';
                continue;
            }
            $aktuell .= $z;
        }
        $teile[] = $aktuell;
        $wert = trim(array_shift($teile));

        $parameter = [];
        $fortsetzungen = [];
        foreach ($teile as $teil) {
            $gleich = strpos($teil, '=');
            if ($gleich === false) {
                continue;
            }
            $name = strtolower(trim(substr($teil, 0, $gleich)));
            $inhalt = trim(substr($teil, $gleich + 1));
            if (preg_match('/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $name, $t) === 1) {
                $kodiert = isset($t[3]) || (!isset($t[2]) || $t[2] === '') && str_ends_with($name, '*');
                $fortsetzungen[$t[1]][(int) ($t[2] ?? 0)] = [$inhalt, $kodiert];
                continue;
            }
            $parameter[$name] = self::woerter($inhalt);
        }
        foreach ($fortsetzungen as $name => $stuecke) {
            ksort($stuecke);
            $zeichensatz = 'UTF-8';
            $text = '';
            foreach ($stuecke as $nr => [$inhalt, $kodiert]) {
                if ($kodiert) {
                    if ($nr === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $inhalt, $t) === 1) {
                        $zeichensatz = $t[1] !== '' ? $t[1] : 'UTF-8';
                        $inhalt = $t[2];
                    }
                    $inhalt = rawurldecode($inhalt);
                }
                $text .= $inhalt;
            }
            $parameter[$name] = self::zuUtf8($text, $zeichensatz);
        }

        return [$wert, $parameter];
    }

    /** RFC 2047 encoded words, whitespace between two of them dropped. */
    private static function woerter(string $wert): string
    {
        $wert = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $wert) ?? $wert;
        $ergebnis = preg_replace_callback(
            '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/',
            static function (array $t): string {
                $zeichensatz = explode('*', $t[1])[0];
                $daten = strtoupper($t[2]) === 'B'
                    ? (string) base64_decode($t[3], false)
                    : quoted_printable_decode(str_replace('_', ' ', $t[3]));

                return self::zuUtf8($daten, $zeichensatz);
            },
            $wert,
        ) ?? $wert;

        return self::zuUtf8($ergebnis, 'UTF-8');
    }

    private static function zuUtf8(string $text, string $zeichensatz): string
    {
        $zeichensatz = strtoupper(trim($zeichensatz));
        if ($zeichensatz === 'UTF-8' || $zeichensatz === 'US-ASCII' || $zeichensatz === '') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        try {
            $umgewandelt = mb_convert_encoding($text, 'UTF-8', $zeichensatz);
        } catch (\ValueError) {
            $umgewandelt = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        return $umgewandelt;
    }

    /** @return array{0: string, 1: string} display name and address (lower case) */
    private static function absender(string $from): array
    {
        $from = self::woerter($from);
        if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $from, $t) === 1) {
            $name = trim(trim($t[1]), " \t\"'");

            return [$name, strtolower(trim($t[2]))];
        }
        $adresse = trim(preg_replace('/\([^)]*\)/', '', $from) ?? $from);

        return ['', strtolower($adresse)];
    }

    /** @param array<string, list<string>> $felder */
    private static function spam(array $felder): bool
    {
        foreach ($felder['x-spam-flag'] ?? [] as $wert) {
            if (strtolower(trim($wert)) === 'yes') {
                return true;
            }
        }
        foreach ($felder['x-spam-status'] ?? [] as $wert) {
            if (preg_match('/^\s*yes\b/i', $wert) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function dateiname(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?? '';

        return mb_substr(trim($name), 0, 200);
    }
}
