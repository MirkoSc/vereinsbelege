<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A recorded cash count ("Kassensturz", M9-1, issue #59,
 * docs/spec/04-bank-und-abgleich.md section 1), decrypted: what the cash
 * box should have held on $countedOn and what was counted, in integer
 * cents. The expected amount is the one computed at the time of the count.
 */
final readonly class CashCount
{
    public function __construct(
        public int $id,
        public int $accountId,
        public \DateTimeImmutable $countedOn,
        public int $expected,
        public int $counted,
        public string $currency,
        public string $note,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** Counted minus expected: negative is a shortfall, positive a surplus. */
    public function differenz(): int
    {
        return $this->counted - $this->expected;
    }

    public function ergebnis(): CashCountOutcome
    {
        return CashCountOutcome::fuer($this->differenz());
    }
}
