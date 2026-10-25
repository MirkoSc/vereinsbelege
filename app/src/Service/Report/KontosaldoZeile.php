<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;

/** One account's balance over the period of the Kassenprüfung (issue #74), with the account. */
final readonly class KontosaldoZeile
{
    public function __construct(
        public BankAccount $konto,
        public Kontosaldo $saldo,
    ) {
    }
}
