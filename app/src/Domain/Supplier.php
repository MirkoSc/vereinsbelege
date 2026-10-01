<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A supplier or payer with its data decrypted (M6-2, issue #36,
 * docs/spec/03-erfassung-und-ki.md section 7). Only exists inside a request
 * whose session has the vault unlocked.
 *
 * $mergedInto is set once the supplier was merged into another (issue
 * #39/M6-5): it no longer shows in the list and cannot be changed.
 */
final readonly class Supplier
{
    public function __construct(
        public int $id,
        public SupplierRole $role,
        public SupplierData $data,
        public ?int $defaultCategoryId,
        public SupplierOrigin $createdVia,
        public bool $needsReview,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?int $mergedInto = null,
    ) {
    }
}
