<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `supplier` row as stored: the plaintext structure plus the sealed
 * data key and the ciphertext. What App\Repository\SupplierRepository
 * returns; App\Service\MasterData\SupplierService turns it into a
 * App\Domain\Supplier once a vault is at hand.
 */
final readonly class SupplierRecord
{
    public function __construct(
        public int $id,
        public SupplierRole $role,
        public string $dekSealed,
        public string $dataEnc,
        public ?int $defaultCategoryId,
        public SupplierOrigin $createdVia,
        public bool $needsReview,
        public ?int $mergedInto,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
