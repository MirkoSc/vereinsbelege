<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One open reimbursement whose submitter IBAN is the counterparty of a
 * booking (M10-3, issue #67) - decrypted, lives only in the request.
 */
final readonly class Erstattungskandidat
{
    /**
     * @param int $offen what is still to reimburse, cents > 0
     */
    public function __construct(
        public int $invoiceId,
        public int $documentId,
        public \DateTimeImmutable $invoiceDate,
        public string $invoiceNumber,
        public string $purposeShort,
        public string $einreicher,
        public int $offen,
    ) {
    }
}
