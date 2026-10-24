<?php

declare(strict_types=1);

namespace App\Service\Suche;

/**
 * The one normalisation both sides of the full-text search use (issue #94,
 * docs/spec/03-erfassung-und-ki.md section 10): what goes into the session
 * index and what the person types are made comparable the same way, so a
 * plain substring test decides a match.
 *
 * Lower case, every run of whitespace (page breaks, non-breaking spaces,
 * line breaks of a text layer) one space, soft hyphens and zero-width
 * characters gone - a text layer splits "Rech\u{AD}nung" where the PDF
 * hyphenated it. Umlauts stay as they are: "Müller" finds "MÜLLER", not
 * "Mueller"; anything cleverer would be guessing.
 */
final class Suchtext
{
    /**
     * Upper bound per document in the index, in bytes after normalisation:
     * the first ~10 pages of an invoice. Keeps one document from inflating a
     * session's index (CLAUDE.md section 1, short requests; spec: size).
     */
    public const int MAX_BYTES_JE_DOKUMENT = 32768;

    private function __construct()
    {
    }

    public static function normalisiere(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $text = (string) preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text);
        // What is left of control and format characters: soft hyphen,
        // zero-width space and joiners, BOM, stray control bytes of a text
        // layer (they would also blow up the JSON of an index part).
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);

        return trim(mb_strtolower($text, 'UTF-8'));
    }

    /**
     * Several texts of one document as one normalised string, cut to
     * MAX_BYTES_JE_DOKUMENT on a character boundary. Texts are joined with
     * " | ", so a phrase does not run from the end of one source into the
     * start of the next by accident.
     *
     * @param list<string> $texte
     */
    public static function fuerIndex(array $texte): string
    {
        $teile = [];
        foreach ($texte as $text) {
            $normal = self::normalisiere($text);
            if ($normal !== '') {
                $teile[] = $normal;
            }
        }
        $ganz = implode(' | ', $teile);

        return strlen($ganz) <= self::MAX_BYTES_JE_DOKUMENT ? $ganz : mb_strcut($ganz, 0, self::MAX_BYTES_JE_DOKUMENT, 'UTF-8');
    }
}
