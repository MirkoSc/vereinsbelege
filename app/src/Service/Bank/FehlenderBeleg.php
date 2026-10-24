<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransaction;

/**
 * A booking that needs a receipt and has none, or not enough (M10-5,
 * issue #69): `fehlt` or `teilweise`, with the part no receipt explains.
 */
final readonly class FehlenderBeleg
{
    /**
     * @param int $fehlt cents of the booking no allocation explains yet, positive
     */
    public function __construct(
        public BankTransaction $buchung,
        public int $fehlt,
    ) {
    }
}
