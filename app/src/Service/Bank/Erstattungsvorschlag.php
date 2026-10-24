<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransaction;

/**
 * What the reimbursement matching offers for one booking (M10-3, issue
 * #67): the open reimbursements of the counterparty's IBAN and the
 * combinations of them that add up to what the booking leaves open.
 */
final readonly class Erstattungsvorschlag
{
    /**
     * @param int $offen what of the booking is not yet allocated, cents
     * @param string|null $hinweis why nothing is searched (income, no IBAN, nothing open), null when it is
     * @param list<Erstattungskandidat> $kandidaten open reimbursements of this IBAN, oldest first
     * @param list<list<Erstattungskandidat>> $kombinationen each adds up to $offen exactly, smallest first
     * @param bool $vollstaendig false when a bound cut the search short
     */
    public function __construct(
        public BankTransaction $buchung,
        public int $offen,
        public ?string $hinweis,
        public array $kandidaten = [],
        public array $kombinationen = [],
        public bool $vollstaendig = true,
    ) {
    }
}
