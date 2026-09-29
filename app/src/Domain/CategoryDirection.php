<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which side of the books a category belongs to (`category.direction`,
 * docs/spec/02-datenmodell.md "Kategorien"). `beide` is offered for
 * receipts and bookings of either direction.
 */
enum CategoryDirection: string
{
    case Einnahme = 'einnahme';
    case Ausgabe = 'ausgabe';
    case Beide = 'beide';

    public function label(): string
    {
        return match ($this) {
            self::Einnahme => 'Einnahme',
            self::Ausgabe => 'Ausgabe',
            self::Beide => 'Einnahmen und Ausgaben',
        };
    }

    /** Heading of this direction's group on the list page. */
    public function gruppe(): string
    {
        return match ($this) {
            self::Einnahme => 'Einnahmen',
            self::Ausgabe => 'Ausgaben',
            self::Beide => 'Einnahmen und Ausgaben',
        };
    }
}
