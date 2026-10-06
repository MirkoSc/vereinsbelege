<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One booking, decrypted (M9-5, issue #63, docs/spec/04-bank-und-abgleich.md
 * section 1): the plaintext structure of `bank_transaction` plus what its
 * `data_enc` holds. Built by App\Service\Bank\Buchungen in a session with
 * an unlocked vault - never logged, never put into a URL.
 */
final readonly class BankTransaction
{
    /**
     * @param int $amount integer cents, signed: income positive, an expense negative
     */
    public function __construct(
        public int $id,
        public int $accountId,
        public ?int $importId,
        public \DateTimeImmutable $bookingDate,
        public ?\DateTimeImmutable $valueDate,
        public BankTransactionDirection $direction,
        public ?int $categoryId,
        public bool $docRequired,
        public BankTransactionDocStatus $docStatus,
        public BankTransactionSource $source,
        public int $amount,
        public string $currency,
        public string $counterpartyName,
        public string $counterpartyIban,
        public string $purpose,
        public string $bookingText,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** Entered by hand - only those may be changed or deleted; an imported booking is what the bank says. */
    public function istManuell(): bool
    {
        return $this->source === BankTransactionSource::Manuell;
    }
}
