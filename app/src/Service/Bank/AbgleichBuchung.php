<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Domain\Iban;
use App\Service\MasterData\SupplierKeys;

/**
 * What the scoring knows of one booking - decrypted, in the session only
 * (M10-2, issue #66). The search keys are derived once here, not per pair.
 */
final readonly class AbgleichBuchung
{
    /** Purpose and end-to-end reference as Punktebewertung::suchtext(). */
    public string $suchtext;

    /** The same, letters and digits only - the candidate index searches it. */
    public string $kompakt;

    public string $ibanNorm;

    public string $mandatNorm;

    public string $glaeubigerNorm;

    public function __construct(
        public int $id,
        public BankTransactionDirection $richtung,
        /** What is not yet allocated, in cents, positive (Belegstand::offen()). */
        public int $offen,
        public \DateTimeImmutable $buchungsdatum,
        public string $gegenseiteName,
        public string $gegenseiteIban,
        public string $verwendungszweck,
        public string $endToEndReferenz = '',
        public string $mandatsreferenz = '',
        public string $glaeubigerId = '',
        public string $waehrung = 'EUR',
    ) {
        $this->suchtext = Punktebewertung::suchtext($verwendungszweck . ' ' . $endToEndReferenz);
        $this->kompakt = str_replace(' ', '', $this->suchtext);
        $this->ibanNorm = Iban::normalisieren($gegenseiteIban);
        $this->mandatNorm = SupplierKeys::kennung($mandatsreferenz);
        $this->glaeubigerNorm = SupplierKeys::kennung($glaeubigerId);
    }
}
