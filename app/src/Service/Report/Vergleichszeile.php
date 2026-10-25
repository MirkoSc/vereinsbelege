<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * One row of the comparison with the previous year: the key in the current
 * period (a month key of the previous year is moved forward by a year so
 * that January meets January) and both sums.
 */
final readonly class Vergleichszeile
{
    public function __construct(
        public int|string|null $schluessel,
        public Summen $aktuell,
        public Summen $vorjahr,
    ) {
    }

    /** Current minus previous year, per side. */
    public function differenz(): Summen
    {
        return $this->aktuell->minus($this->vorjahr);
    }
}
