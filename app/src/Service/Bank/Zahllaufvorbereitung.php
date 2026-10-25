<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankAccount;

/**
 * What the "new payment run" page shows (issue #85): the payees with their
 * open reimbursements, the accounts a run can pay from (bank accounts,
 * active, with a valid IBAN) and how many receipts could not be decrypted.
 */
final readonly class Zahllaufvorbereitung
{
    /**
     * @param list<ZahllaufGruppe> $gruppen
     * @param list<BankAccount> $konten
     */
    public function __construct(
        public array $gruppen,
        public array $konten,
        public int $unlesbar,
    ) {
    }
}
