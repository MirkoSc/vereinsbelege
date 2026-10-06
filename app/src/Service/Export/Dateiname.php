<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * Makes one folder or file name safe for a ZIP that is unpacked on Windows,
 * macOS or Linux (issue #75/M12-1, docs/spec/05-auswertung-und-export.md
 * section 2): umlauts stay, the characters Windows forbids (`\/:*?"<>|`) and
 * control characters become `-`, the length is capped, and nothing can
 * climb out of the export folder.
 */
final class Dateiname
{
    /**
     * Longest folder or file name (without extension and collision suffix),
     * in characters. Several levels of this still fit into Windows' classic
     * 260-character path limit.
     */
    public const int MAX_LAENGE = 80;

    /** Windows device names - a folder or file called like this cannot be created. */
    private const string RESERVIERT = '/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i';

    private function __construct()
    {
    }

    /**
     * Replaces the forbidden characters inside one placeholder value, so a
     * value like "A/B" can never open a folder level of its own.
     */
    public static function zeichen(string $wert): string
    {
        $wert = mb_scrub($wert, 'UTF-8');
        // Line breaks and tabs are white space to a reader, not a mistake.
        $wert = (string) preg_replace('/[\t\n\v\f\r]/', ' ', $wert);

        return (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u', '-', $wert);
    }

    /**
     * One finished folder or file name (without extension): forbidden
     * characters replaced, runs of white space collapsed, no leading space,
     * no trailing space or dot (Windows drops them, so "." and ".." end up
     * empty), at most $maxLaenge characters, no Windows device name.
     * An empty result means: this level is dropped.
     */
    public static function segment(string $wert, int $maxLaenge = self::MAX_LAENGE): string
    {
        $wert = self::zeichen($wert);
        $wert = (string) preg_replace('/\s+/u', ' ', $wert);
        $wert = self::kuerzen(rtrim(ltrim($wert, ' '), ' .'), $maxLaenge);

        $stamm = explode('.', $wert, 2)[0];
        if (preg_match(self::RESERVIERT, rtrim($stamm, ' ')) === 1) {
            $wert = rtrim($stamm, ' ') . '_' . substr($wert, strlen($stamm));
        }

        return $wert;
    }

    /**
     * A file extension as it may follow the dot: lower case, letters and
     * digits only, at most 5 characters - "PDF" → "pdf", "" stays "".
     */
    public static function endung(string $endung): string
    {
        $endung = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $endung));

        return substr($endung, 0, 5);
    }

    private static function kuerzen(string $wert, int $maxLaenge): string
    {
        if (mb_strlen($wert) <= $maxLaenge) {
            return $wert;
        }

        return rtrim(mb_substr($wert, 0, max(1, $maxLaenge)), ' .');
    }
}
