<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `bank_account` row as stored: the plaintext structure plus the sealed
 * data key and the ciphertexts. What App\Repository\BankAccountRepository
 * returns; App\Service\Bank\BankAccountService turns it into a
 * App\Domain\BankAccount once a vault is at hand.
 */
final readonly class BankAccountRecord
{
    public function __construct(
        public int $id,
        public BankAccountKind $kind,
        public string $dekSealed,
        public string $dataEnc,
        public ?string $ibanBi,
        public string $openingBalanceEnc,
        public \DateTimeImmutable $openingDate,
        public bool $active,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
