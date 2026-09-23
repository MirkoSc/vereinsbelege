<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `cost_center` (migrations/010_role.sql, docs/spec/
 * 02-datenmodell.md "Fachdaten"): a name, its display order and whether it
 * may still be picked.
 *
 * Plaintext by design: structural master data, not club data, and it is
 * read before any vault is involved (the cost-center scope, docs/spec/
 * 01-sicherheit.md section 4).
 */
final readonly class CostCenter
{
    public function __construct(
        public int $id,
        public string $name,
        public int $sort,
        public bool $active,
    ) {
    }
}
