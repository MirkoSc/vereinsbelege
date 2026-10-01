<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What saving an account reports back to the page: the row and which
 * fields changed - their names only, which is what the audit log keeps,
 * never the values.
 */
final readonly class BankAccountSaved
{
    /**
     * @param list<string> $geaenderteFelder storage names, e.g. "iban", "opening_balance"
     */
    public function __construct(
        public int $id,
        public array $geaenderteFelder,
    ) {
    }
}
