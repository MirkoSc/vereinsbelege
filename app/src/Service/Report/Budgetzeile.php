<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * Soll against Ist of one budget (issue #92). Integer cents and tenths of
 * a percent throughout - never a float.
 */
final readonly class Budgetzeile
{
    /**
     * @param int $ist the expenses of the lines the budget covers (credit
     *        notes reduce it, so it can be negative)
     * @param int $anzahl how many lines make up $ist
     * @param int|null $abweichungPromille Soll − Ist in tenths of a percent
     *        of the Soll, rounded half away from zero; null for a Soll of 0
     * @param int|null $ausschoepfungPromille Ist in tenths of a percent of
     *        the Soll, same rounding; null for a Soll of 0
     */
    public function __construct(
        public Budgetsoll $soll,
        public int $ist,
        public int $anzahl,
        public ?int $abweichungPromille,
        public ?int $ausschoepfungPromille,
    ) {
    }

    /** Soll − Ist: positive = still available, negative = overspent. */
    public function abweichung(): int
    {
        return $this->soll->betrag - $this->ist;
    }

    public function ueberschritten(): bool
    {
        return $this->ist > $this->soll->betrag;
    }

    /** Bar length 0 … 1000; an overspent budget has the full bar. */
    public function balkenPromille(): int
    {
        if ($this->soll->betrag === 0) {
            return $this->ist > 0 ? 1000 : 0;
        }

        return max(0, min(1000, $this->ausschoepfungPromille ?? 0));
    }
}
