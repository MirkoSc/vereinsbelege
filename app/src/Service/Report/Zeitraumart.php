<?php

declare(strict_types=1);

namespace App\Service\Report;

/** The kinds of period a report page offers (05 section 1, issue #73). */
enum Zeitraumart: string
{
    case Jahr = 'jahr';
    case Quartal = 'quartal';
    case Monat = 'monat';
    case Frei = 'frei';

    public function label(): string
    {
        return match ($this) {
            self::Jahr => 'Kalenderjahr',
            self::Quartal => 'Quartal',
            self::Monat => 'Monat',
            self::Frei => 'Frei (von – bis)',
        };
    }
}
