<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One booking, decrypted (M9-5, issue #63, docs/spec/04-bank-und-abgleich.md
 * section 1): the plaintext structure of `bank_transaction` plus what its
 * `data_enc` holds, and since M9-6 where receipt status and category come
 * from (`doc_source`, `category_source`, `rule_id`). Built by App\Service\Bank\Buchungen in a session with
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
        public ?int $ruleId = null,
        public BankTransactionSetBy $docSource = BankTransactionSetBy::Standard,
        public ?BankTransactionSetBy $categorySource = null,
    ) {
    }

    /** What rules match against (M9-6). */
    public function merkmale(): Buchungsmerkmale
    {
        return new Buchungsmerkmale($this->direction, $this->purpose, $this->bookingText, $this->counterpartyName, $this->counterpartyIban);
    }

    /** Entered by hand - only those may be changed or deleted; an imported booking is what the bank says. */
    public function istManuell(): bool
    {
        return $this->source === BankTransactionSource::Manuell;
    }
}
