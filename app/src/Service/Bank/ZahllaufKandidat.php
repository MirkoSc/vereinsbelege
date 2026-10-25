<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One open reimbursement on the "new payment run" page (issue #85): the
 * receipt and, when it cannot go into a run, why not.
 */
final readonly class ZahllaufKandidat
{
    public function __construct(
        public OffenerPosten $posten,
        public ?string $sperrgrund = null,
    ) {
    }

    public function waehlbar(): bool
    {
        return $this->sperrgrund === null;
    }
}
