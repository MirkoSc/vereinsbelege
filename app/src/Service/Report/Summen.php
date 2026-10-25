<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\InvoiceDirection;

/**
 * Income, expenses and count of a set of report lines, in integer cents.
 * A credit note (or a refund settling a receipt of the other direction)
 * enters with a negative amount and reduces its side, so either side can
 * be negative.
 */
final readonly class Summen
{
    public function __construct(
        public int $einnahmen = 0,
        public int $ausgaben = 0,
        public int $anzahl = 0,
    ) {
    }

    public function saldo(): int
    {
        return $this->einnahmen - $this->ausgaben;
    }

    /** Size for sorting: both sides, regardless of sign. */
    public function umfang(): int
    {
        return abs($this->einnahmen) + abs($this->ausgaben);
    }

    public function mit(Posten $posten): self
    {
        return $posten->art === InvoiceDirection::Einnahme
            ? new self($this->einnahmen + $posten->betrag, $this->ausgaben, $this->anzahl + 1)
            : new self($this->einnahmen, $this->ausgaben + $posten->betrag, $this->anzahl + 1);
    }

    public function plus(self $andere): self
    {
        return new self($this->einnahmen + $andere->einnahmen, $this->ausgaben + $andere->ausgaben, $this->anzahl + $andere->anzahl);
    }

    public function minus(self $andere): self
    {
        return new self($this->einnahmen - $andere->einnahmen, $this->ausgaben - $andere->ausgaben, $this->anzahl - $andere->anzahl);
    }
}
