<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\CashCountOutcome;

/**
 * A checked but not yet stored cash count: what the cash box should hold on
 * $datum, what was counted and the note - in integer cents. The page shows
 * it before saving ("Differenz berechnen"); App\Service\Bank\Kassensturz::
 * erfassen() stores exactly these figures.
 */
final readonly class KassensturzVorschau
{
    public function __construct(
        public \DateTimeImmutable $datum,
        public int $soll,
        public int $ist,
        public string $notiz,
    ) {
    }

    /** Counted minus expected: negative is a shortfall, positive a surplus. */
    public function differenz(): int
    {
        return $this->ist - $this->soll;
    }

    public function ergebnis(): CashCountOutcome
    {
        return CashCountOutcome::fuer($this->differenz());
    }
}
