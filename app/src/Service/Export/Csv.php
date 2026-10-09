<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * CSV the way Excel opens it correctly in Germany
 * (docs/spec/05-auswertung-und-export.md section 1 "Export CSV": UTF-8 with
 * BOM, semicolon, German number format) - first used for the `index.csv`
 * of the ZIP export (issue #76/M12-2). Amounts are formatted by the caller
 * (App\Service\Export\PfadMuster::betrag(), "1.234,56").
 */
final class Csv
{
    /** Makes Excel read the file as UTF-8 - umlauts stay intact. */
    public const string BOM = "\u{FEFF}";

    public const string TRENNER = ';';

    public const string ZEILENENDE = "\r\n";

    private function __construct()
    {
    }

    /**
     * One line, ending in CRLF. A cell holding the separator, a quote or a
     * line break is quoted, quotes inside doubled (RFC 4180).
     *
     * @param list<string> $zellen
     */
    public static function zeile(array $zellen): string
    {
        $teile = [];
        foreach ($zellen as $zelle) {
            $zelle = mb_scrub($zelle, 'UTF-8');
            $teile[] = strpbrk($zelle, self::TRENNER . "\"\r\n") === false
                ? $zelle
                : '"' . str_replace('"', '""', $zelle) . '"';
        }

        return implode(self::TRENNER, $teile) . self::ZEILENENDE;
    }

    /**
     * A text cell that a spreadsheet must not run as a formula: a value
     * beginning with = + - @ (or a tab or carriage return) gets a leading
     * apostrophe. Supplier names and invoice numbers can come from a
     * public submission or an AI reading - neither may plant a formula in
     * the treasurer's Excel. Only for text: an amount like "-12,50" stays a
     * number.
     */
    public static function text(string $wert): string
    {
        return $wert !== '' && str_contains("=+-@\t\r", $wert[0]) ? "'" . $wert : $wert;
    }
}
