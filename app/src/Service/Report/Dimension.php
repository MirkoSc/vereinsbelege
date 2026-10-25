<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * What a report groups by (docs/spec/05-auswertung-und-export.md section 1
 * "Wohin fließt das Geld"). The key of a group is the plaintext id of the
 * master data row (null = none assigned), the month as `YYYY-MM` or the
 * sphere value - names are resolved by the page, never by the calculation.
 */
enum Dimension: string
{
    case Kategorie = 'kategorie';
    case Lieferant = 'lieferant';
    case Kostenstelle = 'kostenstelle';
    case Sphaere = 'sphaere';
    case Monat = 'monat';
    case Konto = 'konto';

    /**
     * A key as it may come from a request: ids as int (`'12'` -> 12), a
     * month or sphere as string, '' as none.
     */
    public function normalisiere(int|string|null $schluessel): int|string|null
    {
        if ($schluessel === null || $schluessel === '') {
            return null;
        }
        if ($this === self::Monat || $this === self::Sphaere) {
            return (string) $schluessel;
        }

        return is_int($schluessel) ? $schluessel : (preg_match('/^[1-9][0-9]{0,18}$/', $schluessel) === 1 ? (int) $schluessel : $schluessel);
    }

    public function schluessel(Posten $posten): int|string|null
    {
        return match ($this) {
            self::Kategorie => $posten->kategorieId,
            self::Lieferant => $posten->lieferantId,
            self::Kostenstelle => $posten->kostenstelleId,
            self::Sphaere => $posten->sphaere,
            self::Monat => $posten->datum->format('Y-m'),
            self::Konto => $posten->kontoId,
        };
    }
}
