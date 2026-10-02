<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * What a merge (issue #39/M6-5) did: how many receipts moved to the target
 * and how many locked ones stayed with the source.
 */
final readonly class SupplierMerged
{
    public function __construct(
        public int $quelleId,
        public int $zielId,
        public int $umgehaengt,
        public int $festgeschrieben,
    ) {
    }
}
