<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * One decrypted budget (issue #92): the planned expenses (Soll) of a
 * calendar year for a category, a cost center or both. A null key means
 * "any": a budget for a category alone counts that category on every cost
 * center.
 */
final readonly class Budgetsoll
{
    /**
     * @param int $betrag integer cents, >= 0
     */
    public function __construct(
        public int $id,
        public int $jahr,
        public ?int $kategorieId,
        public ?int $kostenstelleId,
        public int $betrag,
    ) {
    }

    /** Whether a report line counts towards this budget. */
    public function trifft(Posten $posten): bool
    {
        return ($this->kategorieId === null || $posten->kategorieId === $this->kategorieId)
            && ($this->kostenstelleId === null || $posten->kostenstelleId === $this->kostenstelleId);
    }
}
