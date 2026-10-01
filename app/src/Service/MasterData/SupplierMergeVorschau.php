<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\Supplier;

/**
 * What the preview page of a merge shows (issue #39/M6-5): both suppliers
 * as they are, the target as it would be, and the source's receipts -
 * those that move and the locked ones that stay.
 */
final readonly class SupplierMergeVorschau
{
    /**
     * @param array{offen: int, festgeschrieben: int} $belege
     */
    public function __construct(
        public Supplier $quelle,
        public Supplier $ziel,
        public SupplierMergePlan $plan,
        public array $belege,
    ) {
    }
}
