<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `cash_count` row as stored: structure plus the sealed data key and
 * the ciphertext. What App\Repository\CashCountRepository returns;
 * App\Service\Bank\Kassensturz decrypts it into a App\Domain\CashCount.
 */
final readonly class CashCountRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public \DateTimeImmutable $countedOn,
        public string $dekSealed,
        public string $dataEnc,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
