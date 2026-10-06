<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankAccount;
use App\Domain\BankTransactionDirection;

/**
 * A checked manual booking (M9-5, issue #63) before it is written - what
 * App\Service\Bank\Buchungen made of the form. The amount is positive; the
 * direction gives the sign it is stored with.
 */
final readonly class BuchungEingabe
{
    public function __construct(
        public BankAccount $konto,
        public \DateTimeImmutable $datum,
        public int $betrag,
        public BankTransactionDirection $richtung,
        public int $kategorieId,
        public string $zweck,
        public string $gegenseite,
        public bool $belegNoetig,
    ) {
    }

    /** Signed cents as stored: an expense negative. */
    public function cent(): int
    {
        return $this->richtung === BankTransactionDirection::Ausgabe ? -$this->betrag : $this->betrag;
    }

    /**
     * The content of `data_enc` - the same keys as an imported booking, so
     * every reader treats both alike.
     *
     * @return array<string, int|string>
     */
    public function daten(string $waehrung): array
    {
        return [
            'amount' => $this->cent(),
            'currency' => $waehrung,
            'counterparty_name' => $this->gegenseite,
            'counterparty_iban' => '',
            'purpose' => $this->zweck,
            'eref' => '',
            'mref' => '',
            'cred' => '',
            'gvc' => '',
            'booking_text' => '',
        ];
    }
}
