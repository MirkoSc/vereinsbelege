<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

use App\Service\Bank\Umsatz;

/**
 * One booked transaction of a CSV export. The transaction itself is the
 * same value object the MT940 parser yields, so the import (M9-4) treats
 * both formats alike; CSV adds what MT940 keeps elsewhere: the currency per
 * row and, where the bank sends it, the balance after the booking.
 */
final readonly class CsvBuchung
{
    public function __construct(
        public int $zeile,
        public Umsatz $umsatz,
        public string $waehrung,
        public ?int $saldoCent,
    ) {
    }
}
