<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a rule looks at in a receipt (M7-7, issue #49, docs/spec/
 * 03-erfassung-und-ki.md section 8): direction, purpose and the partner -
 * its name and aliases, its IBANs. Decrypted, so only ever built in a
 * session with an unlocked vault (the review page).
 */
final readonly class Belegmerkmale
{
    /**
     * @param list<string> $partnerNamen name and aliases of the supplier or payer
     * @param list<string> $partnerIbans its IBANs, in any notation
     */
    public function __construct(
        public InvoiceDirection $richtung,
        public string $zweck,
        public array $partnerNamen,
        public array $partnerIbans,
    ) {
    }
}
