<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `bank_import` row (migrations/025_bank_import.sql, M9-4, issue #62).
 * Plaintext only: ids, the format, dates, counts and states.
 */
final readonly class BankImportRecord
{
    public function __construct(
        public int $id,
        public ?int $accountId,
        public string $format,
        public int $fileBlobId,
        public ?string $sourceBi,
        public BankImportStatus $status,
        public int $nextIndex,
        public ?BankImportStats $stats,
        public ?BalanceCheck $balanceCheck,
        public ?\DateTimeImmutable $periodFrom,
        public ?\DateTimeImmutable $periodTo,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
        public ?int $importedBy,
        public ?\DateTimeImmutable $importedAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
