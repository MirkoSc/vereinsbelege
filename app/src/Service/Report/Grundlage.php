<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * What a report counts (docs/spec/05-auswertung-und-export.md section 1,
 * E-44): the checked receipts (receipt date, gross) or the bookings (money
 * flow per account - the default, because income often has no receipt).
 */
enum Grundlage: string
{
    case Buchungen = 'buchungen';
    case Belege = 'belege';

    public const self STANDARD = self::Buchungen;

    public function label(): string
    {
        return match ($this) {
            self::Buchungen => 'Buchungen (Zahlungsfluss)',
            self::Belege => 'Belege (Rechnungsdatum)',
        };
    }
}
