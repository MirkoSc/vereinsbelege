<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a receipt stands with its payment (M10-1, issue #65): gross amount,
 * what the allocations settle, the Zahlungsweg and a closed difference -
 * and, derived from those, the status and what is still open. Computed by
 * App\Service\Bank\Zuordnung after decrypting; what the matching (M10-2),
 * the reimbursements (M10-3) and the lists (M10-5) work with.
 */
final readonly class Zahlungsstand
{
    public function __construct(
        public Zahlungsweg $weg,
        public int $brutto,
        public int $zugeordnet,
        public ?Differenzgrund $differenz,
    ) {
    }

    public function status(): PaymentStatus
    {
        return PaymentStatus::ableiten($this->weg, $this->brutto, $this->zugeordnet, $this->differenz);
    }

    /**
     * What a booking may still settle, in cents: gross minus allocated -
     * zero once settled, or when no booking is expected at all. Never
     * negative.
     */
    public function offen(): int
    {
        if (!$this->weg->erwartetBuchung() || $this->status()->ausgeglichen()) {
            return 0;
        }

        return max(0, $this->brutto - $this->zugeordnet);
    }

    /** The shortfall a Differenzgrund would explain: gross minus allocated while something is allocated. */
    public function differenzbetrag(): int
    {
        return $this->zugeordnet > 0 ? max(0, $this->brutto - $this->zugeordnet) : 0;
    }
}
