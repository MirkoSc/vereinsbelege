<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A bank account or cash box, decrypted (M9-1, issue #59,
 * docs/spec/04-bank-und-abgleich.md section 1). Only exists inside a
 * session with the vault unlocked.
 *
 * The opening balance is the balance as of $openingDate, in integer cents
 * (CLAUDE.md section 5: never float).
 */
final readonly class BankAccount
{
    public function __construct(
        public int $id,
        public BankAccountKind $kind,
        public BankAccountData $data,
        public int $openingBalance,
        public string $currency,
        public \DateTimeImmutable $openingDate,
        public bool $active,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
