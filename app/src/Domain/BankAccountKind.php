<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What kind of account a `bank_account` row is (M9-1, issue #59,
 * docs/spec/04-bank-und-abgleich.md section 1): a bank account (current
 * account, savings book) or a cash box. Plaintext, fixed once the account
 * exists. The values are a storage format.
 */
enum BankAccountKind: string
{
    case Bank = 'bank';
    case Kasse = 'kasse';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bankkonto',
            self::Kasse => 'Kasse',
        };
    }

    /** Heading of the group on the list page. */
    public function gruppe(): string
    {
        return match ($this) {
            self::Bank => 'Bankkonten',
            self::Kasse => 'Kassen',
        };
    }

    /**
     * Whether the account has a bank connection (IBAN, BIC, bank name) - a
     * cash box has none.
     */
    public function hatBankverbindung(): bool
    {
        return $this === self::Bank;
    }

    /**
     * Whether the balance may drop below zero: an overdrawn bank account
     * happens, a cash box cannot hold less than nothing.
     */
    public function darfNegativSein(): bool
    {
        return $this === self::Bank;
    }
}
