<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The open reimbursements of one submitter and account (M10-5, issue #69,
 * E-37): grouped by the name given in the submission (case and spacing
 * ignored) and the IBAN, with the sum still open per currency.
 * `mehrdeutig`: the same name also turns up with another IBAN (or
 * without one) - the public submission lets anybody use any name.
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
        public bool $mehrdeutig = false,
    ) {
    }
}
