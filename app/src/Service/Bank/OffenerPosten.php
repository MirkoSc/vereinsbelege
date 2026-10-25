<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Erstattungsart;
use App\Domain\InvoiceRecord;

/**
 * One receipt in the unpaid / reimbursement lists (M10-5, issue #69),
 * decrypted - lives only in the request that opened it.
 */
final readonly class OffenerPosten
{
    /**
     * @param int $brutto gross in cents
     * @param int $offen what a booking still has to settle, in cents
     */
    public function __construct(
        public InvoiceRecord $record,
        public string $lieferant,
        public string $nummer,
        public string $zweck,
        public int $brutto,
        public int $offen,
        public string $waehrung,
        public BelegFaelligkeit $faelligkeit,
        public bool $altbestand,
        public string $einreicher = '',
        public string $iban = '',
        public ?Erstattungsart $erstattungsart = null,
        public string $kontoinhaber = '',
    ) {
    }
}
