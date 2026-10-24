<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;

/**
 * What the scoring knows of one booking - decrypted, in the session only
 * (M10-2, issue #66).
 */
final readonly class AbgleichBuchung
{
    public function __construct(
        public int $id,
        public BankTransactionDirection $richtung,
        /** What is not yet allocated, in cents, positive (Belegstand::offen()). */
        public int $offen,
        public \DateTimeImmutable $buchungsdatum,
        public string $gegenseiteName,
        public string $gegenseiteIban,
        public string $verwendungszweck,
        public string $endToEndReferenz = '',
        public string $mandatsreferenz = '',
        public string $glaeubigerId = '',
    ) {
    }
}
