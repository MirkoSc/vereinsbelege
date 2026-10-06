<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `bank_transaction` row as stored, for the places that decrypt it
 * (M9-4: the balance a statement import compares its opening balance with;
 * M9-5: the booking list, manual bookings, the expected cash). Plaintext
 * structure plus the sealed data key and the ciphertext.
 */
final readonly class BankTransactionRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public ?int $importId,
        public \DateTimeImmutable $bookingDate,
        public BankTransactionDirection $direction,
        public string $dekSealed,
        public string $dataEnc,
        public ?\DateTimeImmutable $valueDate = null,
        public ?int $categoryId = null,
        public bool $docRequired = false,
        public BankTransactionDocStatus $docStatus = BankTransactionDocStatus::NichtNoetig,
        public BankTransactionSource $source = BankTransactionSource::Import,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
