<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Field separator of a CSV export. Backed by a name rather than the
 * character itself, so a tab survives a form field and the database
 * column (`csv_profile.delimiter`) without escaping.
 */
enum CsvTrennzeichen: string
{
    case Semikolon = 'semikolon';
    case Komma = 'komma';
    case Tabulator = 'tab';
    case Senkrechtstrich = 'pipe';

    public function zeichen(): string
    {
        return match ($this) {
            self::Semikolon => ';',
            self::Komma => ',',
            self::Tabulator => "\t",
            self::Senkrechtstrich => '|',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Semikolon => 'Semikolon ( ; )',
            self::Komma => 'Komma ( , )',
            self::Tabulator => 'Tabulator',
            self::Senkrechtstrich => 'Senkrechter Strich ( | )',
        };
    }
}
