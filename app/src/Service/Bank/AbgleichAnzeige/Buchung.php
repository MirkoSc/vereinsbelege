<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

use App\Domain\BankTransaction;

/** A booking as the matching page shows it (M10-2, issue #66) - decrypted, this request only. */
final readonly class Buchung
{
    public function __construct(
        public BankTransaction $buchung,
        public int $offen,
    ) {
    }
}
