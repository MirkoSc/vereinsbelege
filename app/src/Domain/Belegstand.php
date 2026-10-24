<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a booking stands with its receipts (M10-1, issue #65): its amount,
 * what the allocations account for, whether it needs a receipt at all -
 * and, derived, the status and what is still unexplained. The booking-side
 * twin of App\Domain\Zahlungsstand, computed by App\Service\Bank\Zuordnung.
 */
final readonly class Belegstand
{
    /**
     * @param int $betrag the booking's amount in cents, signed as stored
     * @param int $zugeordnet the sum of its allocations in cents, positive
     */
    public function __construct(
        public bool $belegNoetig,
        public int $betrag,
        public int $zugeordnet,
    ) {
    }

    public function status(): BankTransactionDocStatus
    {
        return BankTransactionDocStatus::ableiten($this->belegNoetig, $this->betrag, $this->zugeordnet);
    }

    /** What receipts may still account for, in cents - never negative. */
    public function offen(): int
    {
        return max(0, abs($this->betrag) - $this->zugeordnet);
    }
}
