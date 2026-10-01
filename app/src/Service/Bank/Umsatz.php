<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One transaction (:61: plus its :86:).
 */
final readonly class Umsatz
{
    /**
     * @param int    $cent              signed: credits positive, debits
     *                                  negative; a reversal (RC/RD) carries
     *                                  the sign of its effect on the balance
     * @param bool   $storno            RC/RD
     * @param string $buchungsschluessel transaction type, e.g. "NMSC", "N020"
     * @param ?string $zusatz           supplementary details (second :61: line)
     */
    public function __construct(
        public \DateTimeImmutable $valuta,
        public \DateTimeImmutable $buchungsdatum,
        public int $cent,
        public bool $storno,
        public string $buchungsschluessel,
        public string $kundenreferenz,
        public ?string $bankreferenz,
        public ?string $zusatz,
        public ?Umsatzdetails $details,
    ) {
    }
}
