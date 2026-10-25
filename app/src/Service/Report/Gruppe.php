<?php

declare(strict_types=1);

namespace App\Service\Report;

/** One row of a grouped report: the key (see Dimension) and its sums. */
final readonly class Gruppe
{
    public function __construct(
        public int|string|null $schluessel,
        public Summen $summen,
    ) {
    }
}
