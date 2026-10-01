<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a cash count turned out (docs/spec/04-bank-und-abgleich.md section 1,
 * "Kassensturz"): the cash box matches, holds less than it should
 * (shortfall) or more (surplus). Derived from the difference, never stored.
 */
enum CashCountOutcome
{
    case Stimmt;
    case Fehlbetrag;
    case Ueberschuss;

    /**
     * @param int $differenz counted minus expected, in cents
     */
    public static function fuer(int $differenz): self
    {
        return match (true) {
            $differenz < 0 => self::Fehlbetrag,
            $differenz > 0 => self::Ueberschuss,
            default => self::Stimmt,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Stimmt => 'Kasse stimmt',
            self::Fehlbetrag => 'Fehlbetrag',
            self::Ueberschuss => 'Überschuss',
        };
    }

    /** The designsystem marker (`.marke-…`) the page shows it with. */
    public function marke(): string
    {
        return match ($this) {
            self::Stimmt => 'marke-ok',
            self::Fehlbetrag => 'marke-fehler',
            self::Ueberschuss => 'marke-warnung',
        };
    }
}
