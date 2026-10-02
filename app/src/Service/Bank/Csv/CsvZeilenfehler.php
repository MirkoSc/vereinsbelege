<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * A data row the profile cannot read. The message names the line and the
 * column (header name), never the cell's content.
 */
final readonly class CsvZeilenfehler
{
    public function __construct(
        public int $zeile,
        public string $meldung,
    ) {
    }
}
