<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `transfer` row (M10-6, issue #70): the expense on the paying own
 * account and the income on the receiving one. Plaintext structure only -
 * the amounts are in the two bookings.
 */
final readonly class Umbuchung
{
    public function __construct(
        public int $id,
        public int $ausgabeId,
        public int $einnahmeId,
        public UmbuchungMethode $method,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** The other booking of the pair. */
    public function partnerVon(int $transactionId): int
    {
        return $transactionId === $this->ausgabeId ? $this->einnahmeId : $this->ausgabeId;
    }
}
