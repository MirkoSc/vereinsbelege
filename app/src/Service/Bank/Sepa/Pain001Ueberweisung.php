<?php

declare(strict_types=1);

namespace App\Service\Bank\Sepa;

/**
 * One transfer of a pain.001 file (issue #85): payee, IBAN, amount in
 * integer cents (EUR) and the unstructured remittance text.
 */
final readonly class Pain001Ueberweisung
{
    public function __construct(
        public string $endToEndId,
        public string $name,
        public string $iban,
        public int $cent,
        public string $verwendungszweck,
    ) {
    }
}
