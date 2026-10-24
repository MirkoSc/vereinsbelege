<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The open reimbursements of one submitter (M10-5, issue #69): grouped by
 * the name given in the submission (case and spacing ignored), with the
 * sum still open per currency.
 */
final readonly class Erstattungsempfaenger
{
    /**
     * @param list<OffenerPosten> $posten
     * @param array<string, int> $summen currency => cents still open
     */
    public function __construct(
        public string $name,
        public string $iban,
        public array $posten,
        public array $summen,
    ) {
    }
}
