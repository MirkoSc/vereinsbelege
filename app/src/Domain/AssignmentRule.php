<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One rule for bookings, decrypted (M9-6, issue #64, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M9-6"): what it looks for -
 * a word in purpose or booking text, a counterparty name or IBAN, optionally
 * one direction - and what it does: "kein Beleg nötig" and/or a category.
 * Built by App\Service\Bank\Buchungsregeln in a session with an unlocked
 * vault - label and pattern are club data, never logged or put into a URL.
 */
final readonly class AssignmentRule
{
    /**
     * @param string $stichwort  empty = any purpose
     * @param string $gegenseite empty = any counterparty; an IBAN (normalised) or a part of the name
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $stichwort,
        public string $gegenseite,
        public ?BankTransactionDirection $direction,
        public bool $noReceipt,
        public ?int $categoryId,
        public bool $active,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** Whether the counterparty pattern is an IBAN (matched exactly) rather than part of a name. */
    public function gegenseiteIstIban(): bool
    {
        return $this->gegenseite !== '' && Iban::istGueltig($this->gegenseite);
    }
}
