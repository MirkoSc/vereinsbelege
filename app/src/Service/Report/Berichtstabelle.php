<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Service\Export\Csv;
use App\Service\Processing\Betrag;

/**
 * One table of a report page as it goes into a CSV download
 * (docs/spec/05-auswertung-und-export.md section 1 "Export CSV jeder
 * Tabellenansicht", issue #74): UTF-8 with BOM, semicolon, German number
 * format - Excel opens it correctly (App\Service\Export\Csv).
 *
 * A cell is text, an amount or empty, and the type decides the format:
 * - string: text, guarded against formulas (Csv::text(): a leading
 *   = + - @ tab or CR gets an apostrophe) - names and purposes come from
 *   bank statements and public submissions;
 * - int: integer cents, written as "1.234,56" / "-12,50" (a number for
 *   Excel, never a formula);
 * - null: empty.
 *
 * Built and written in memory; the controller sends the string. Nothing
 * of it touches the disk (CLAUDE.md section 4).
 */
final readonly class Berichtstabelle
{
    /**
     * @param list<string> $kopf
     * @param list<list<string|int|null>> $zeilen
     */
    public function __construct(
        public array $kopf,
        public array $zeilen,
    ) {
    }

    public function csv(): string
    {
        $csv = Csv::BOM . Csv::zeile(array_map(Csv::text(...), $this->kopf));
        foreach ($this->zeilen as $zeile) {
            $csv .= Csv::zeile(array_map(static fn(string|int|null $zelle): string => match (true) {
                $zelle === null => '',
                is_int($zelle) => Betrag::format($zelle),
                default => Csv::text($zelle),
            }, $zeile));
        }

        return $csv;
    }
}
