<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `category` (migrations/017_category.sql, docs/spec/
 * 02-datenmodell.md "Kategorien").
 *
 * Plaintext by design, like cost centers: structural master data the club
 * maintains, not club data - SQL filters and groups by the id.
 * `parentId` is carried but not yet maintained (flat list since M6-1).
 */
final readonly class Category
{
    public function __construct(
        public int $id,
        public string $name,
        public CategoryDirection $direction,
        public ?int $parentId,
        public ?CategoryColor $color,
        public int $sort,
        public bool $active,
        public string $aiHint,
    ) {
    }
}
