<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `assignment_rule` row as stored (migrations/027_assignment_rule.sql,
 * M9-6, issue #64): plaintext structure plus the sealed data key and the
 * ciphertext of label and pattern.
 */
final readonly class AssignmentRuleRecord
{
    public function __construct(
        public int $id,
        public string $dekSealed,
        public string $dataEnc,
        public ?BankTransactionDirection $direction,
        public bool $noReceipt,
        public ?int $categoryId,
        public bool $active,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
