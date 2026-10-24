<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why a receipt counts as settled although its allocations fall short of
 * the gross amount (`invoice.difference_reason`, M10-1, issue #65,
 * docs/spec/04-bank-und-abgleich.md section 5 "Modell"): the difference
 * itself is gross minus allocated, computed after decrypting - never stored.
 */
enum Differenzgrund: string
{
    case Skonto = 'skonto';

    /** Netted with a credit note - the credit note itself is `Zahlungsweg::Keine`. */
    case Gutschrift = 'gutschrift';

    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Skonto => 'Skonto',
            self::Gutschrift => 'mit Gutschrift verrechnet',
            self::Sonstiges => 'sonstige Differenz',
        };
    }
}
