<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a booking came from (`bank_transaction.source`, docs/spec/
 * 02-datenmodell.md "Fachdaten"): a statement import (M9-4) or entered by
 * hand (M9-5). The values are a storage format.
 */
enum BankTransactionSource: string
{
    case Import = 'import';
    case Manuell = 'manuell';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Kontoauszug',
            self::Manuell => 'manuell',
        };
    }
}
