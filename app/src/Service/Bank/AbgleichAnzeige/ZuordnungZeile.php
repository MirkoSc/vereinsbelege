<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

use App\Domain\Allocation;
use App\Domain\BankTransaction;

/**
 * An existing allocation of the selected receipt (M10-4, issue #68). The
 * booking is null when it lies outside the user's scope: then neither its
 * details are shown nor can the allocation be released here.
 */
final readonly class ZuordnungZeile
{
    public function __construct(
        public Allocation $zuordnung,
        public ?BankTransaction $buchung,
    ) {
    }
}
