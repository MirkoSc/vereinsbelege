<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a transfer between own accounts was paired (`transfer.method`,
 * M10-6, issue #70, docs/spec/04-bank-und-abgleich.md section 5): recognised
 * automatically (the counterparty IBAN is an own account and exactly one
 * counter booking fits) or by hand.
 */
enum UmbuchungMethode: string
{
    case Auto = 'auto';
    case Manuell = 'manuell';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'automatisch erkannt',
            self::Manuell => 'von Hand',
        };
    }
}
