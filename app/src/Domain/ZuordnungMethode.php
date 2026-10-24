<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How an allocation came about (`allocation.method`, M10-1, issue #65,
 * docs/spec/04-bank-und-abgleich.md section 5): automatically (score above
 * the threshold, M10-2), a suggestion a person confirmed, or by hand.
 */
enum ZuordnungMethode: string
{
    case Auto = 'auto';
    case VorschlagBestaetigt = 'vorschlag_bestaetigt';
    case Manuell = 'manuell';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'automatisch',
            self::VorschlagBestaetigt => 'Vorschlag bestätigt',
            self::Manuell => 'von Hand',
        };
    }
}
