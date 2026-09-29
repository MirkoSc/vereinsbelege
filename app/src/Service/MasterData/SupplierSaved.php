<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * What saving a supplier reports back to the page: the row, which fields
 * changed (their names only - that is what the audit log keeps, never the
 * values), and whether another supplier has the same name or alias, which
 * is allowed but worth a hint.
 */
final readonly class SupplierSaved
{
    /**
     * @param list<string> $geaenderteFelder storage names, e.g. "iban", "role"
     */
    public function __construct(
        public int $id,
        public array $geaenderteFelder,
        public bool $namensgleich,
    ) {
    }
}
