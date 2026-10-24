<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Domain\Zahlungsweg;

/**
 * What the scoring knows of one receipt - decrypted, in the session only
 * (M10-2, issue #66). Built by App\Service\Bank\Abgleich, consumed by the
 * pure App\Service\Bank\Punktebewertung.
 */
final readonly class AbgleichBeleg
{
    /**
     * @param list<string> $lieferantNamen name and aliases of the supplier
     * @param list<string> $lieferantIbans
     * @param list<string> $mandatsreferenzen
     */
    public function __construct(
        public int $id,
        /** The direction of the booking that settles it (a credit note: the opposite one). */
        public BankTransactionDirection $buchungsrichtung,
        public Zahlungsweg $zahlungsweg,
        public int $brutto,
        /** What is still to be allocated, in cents (Zahlungsstand::offen()). */
        public int $offen,
        public string $rechnungsnummer,
        public \DateTimeImmutable $rechnungsdatum,
        public ?\DateTimeImmutable $faelligkeit,
        public array $lieferantNamen = [],
        public array $lieferantIbans = [],
        public array $mandatsreferenzen = [],
        public string $glaeubigerId = '',
        public string $kundennummer = '',
        /** Reimbursements (M10-3): the IBAN the submitter gave, null when unknown. */
        public ?string $einreicherIban = null,
    ) {
    }
}
