<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What the GiroCode page shows for one unpaid receipt (issue #86, E-40):
 * the receipt, the payee, one code per usable supplier IBAN - or, instead
 * of codes, the reason there is none ($grund).
 */
final readonly class Ueberweisung
{
    /**
     * @param list<GiroCode> $codes
     */
    public function __construct(
        public OffenerPosten $posten,
        public array $codes,
        public ?string $grund,
        public int $verworfeneIbans = 0,
    ) {
    }
}
