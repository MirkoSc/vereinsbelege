<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\SupplierData;
use App\Domain\SupplierRole;

/**
 * The target supplier as App\Service\MasterData\SupplierMerge computed it:
 * what the merge writes and what the preview shows. $geaenderteFelder are
 * the storage names of the target's fields that change - the audit log
 * keeps those, never the values.
 */
final readonly class SupplierMergePlan
{
    /**
     * @param list<string> $geaenderteFelder
     */
    public function __construct(
        public SupplierRole $role,
        public ?int $defaultCategoryId,
        public SupplierData $data,
        public array $geaenderteFelder,
    ) {
    }
}
