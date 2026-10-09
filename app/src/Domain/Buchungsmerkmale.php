<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a rule looks at in a booking (M9-6, issue #64, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M9-6"): direction, purpose,
 * booking text and counterparty - decrypted, so only ever built in a
 * session with an unlocked vault (the statement import, applying a rule).
 */
final readonly class Buchungsmerkmale
{
    public function __construct(
        public BankTransactionDirection $richtung,
        public string $zweck,
        public string $buchungstext,
        public string $gegenseiteName,
        public string $gegenseiteIban,
    ) {
    }
}
