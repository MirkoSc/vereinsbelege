<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Money in or out (`bank_transaction.direction`, M9-4, issue #62,
 * docs/spec/02-datenmodell.md "Fachdaten"). Plaintext, derived from the
 * sign of the amount, so lists can filter without the vault. The values
 * are a storage format and match App\Domain\InvoiceDirection.
 */
enum BankTransactionDirection: string
{
    case Ausgabe = 'ausgabe';
    case Einnahme = 'einnahme';

    /** A booking of zero (a closing with nothing to charge) counts as income: nothing left the account. */
    public static function ausDemBetrag(int $cent): self
    {
        return $cent < 0 ? self::Ausgabe : self::Einnahme;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ausgabe => 'Ausgabe',
            self::Einnahme => 'Einnahme',
        };
    }

    /**
     * Whether a new booking of this direction needs a receipt (E-17,
     * docs/spec/04-bank-und-abgleich.md section 1): an expense does, income
     * does not by default. Rules that change this per booking come with
     * M9-6 (issue #64).
     */
    public function belegNoetigStandard(): bool
    {
        return $this === self::Ausgabe;
    }
}
