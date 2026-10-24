<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * What the person typed into the full-text search (issue #94): split into
 * terms, every one of which must occur in a document's text (AND). "Quoted
 * words" stay together as one term - a phrase. Terms are normalised like
 * the index (Suchtext), so matching is a plain substring test.
 *
 * Deliberately small: no wildcards, no OR, no ranking. A club's few thousand
 * receipts a year are found by a supplier, an invoice number or a word of
 * the purpose; results are listed newest first.
 */
final readonly class Suchanfrage
{
    public const int MAX_ZEICHEN = 200;

    public const int MAX_BEGRIFFE = 8;

    /** Shorter terms match almost everything and are dropped. */
    public const int MIN_ZEICHEN_JE_BEGRIFF = 2;

    private const int AUSSCHNITT_VOR = 60;

    private const int AUSSCHNITT_NACH = 140;

    /**
     * @param list<string> $begriffe normalised, unique, longest first
     */
    private function __construct(public string $eingabe, public array $begriffe)
    {
    }

    public static function aus(string $eingabe): self
    {
        $eingabe = mb_substr(trim(mb_scrub($eingabe, 'UTF-8')), 0, self::MAX_ZEICHEN, 'UTF-8');

        $begriffe = [];
        preg_match_all('/"([^"]*)"|(\S+)/u', $eingabe, $treffer, PREG_SET_ORDER);
        foreach ($treffer as $t) {
            $begriff = Suchtext::normalisiere(($t[1] ?? '') !== '' ? $t[1] : str_replace('"', '', $t[2] ?? ''));
            if (mb_strlen($begriff, 'UTF-8') >= self::MIN_ZEICHEN_JE_BEGRIFF) {
                $begriffe[$begriff] = true;
            }
        }
        // array_keys(): a numeric term ("4711") came back as an int key.
        $begriffe = array_slice(array_map(strval(...), array_keys($begriffe)), 0, self::MAX_BEGRIFFE);
        // Longest first: the highlighting then prefers "rechnung 4711" over
        // "rechnung" where both start at the same place.
        usort($begriffe, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return new self($eingabe, $begriffe);
    }

    public function leer(): bool
    {
        return $this->begriffe === [];
    }

    /** @param string $text normalised (Suchtext::fuerIndex()) */
    public function passt(string $text): bool
    {
        if ($this->begriffe === []) {
            return false;
        }
        foreach ($this->begriffe as $begriff) {
            if (!str_contains($text, $begriff)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short piece of $text around the first match, as segments for the
     * view: [text, is a match]. The view escapes every segment and marks the
     * matches - no HTML is built here.
     *
     * @param string $text normalised (Suchtext::fuerIndex())
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function ausschnitt(string $text): array
    {
        $erste = null;
        foreach ($this->begriffe as $begriff) {
            $pos = strpos($text, $begriff);
            if ($pos !== false && ($erste === null || $pos < $erste)) {
                $erste = $pos;
            }
        }
        if ($erste === null) {
            return [];
        }

        $start = max(0, $erste - self::AUSSCHNITT_VOR);
        $stueck = mb_strcut($text, $start, self::AUSSCHNITT_VOR + self::AUSSCHNITT_NACH, 'UTF-8');
        // mb_strcut() moves the start back to a character boundary.
        $start = strpos($text, $stueck, max(0, $start - 3));
        $start = $start === false ? 0 : $start;
        $davor = $start > 0;
        $danach = $start + strlen($stueck) < strlen($text);

        $segmente = [];
        if ($davor) {
            $segmente[] = ['…', false];
        }
        $pos = 0;
        $laenge = strlen($stueck);
        while ($pos < $laenge) {
            [$fund, $begriff] = $this->naechster($stueck, $pos);
            if ($fund === null) {
                $segmente[] = [substr($stueck, $pos), false];
                break;
            }
            if ($fund > $pos) {
                $segmente[] = [substr($stueck, $pos, $fund - $pos), false];
            }
            $segmente[] = [$begriff, true];
            $pos = $fund + strlen($begriff);
        }
        if ($danach) {
            $segmente[] = ['…', false];
        }

        return $segmente;
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private function naechster(string $stueck, int $ab): array
    {
        $bester = null;
        $begriffBester = '';
        foreach ($this->begriffe as $begriff) {
            $pos = strpos($stueck, $begriff, $ab);
            if ($pos !== false && ($bester === null || $pos < $bester)) {
                $bester = $pos;
                $begriffBester = $begriff;
            }
        }

        return [$bester, $begriffBester];
    }
}
