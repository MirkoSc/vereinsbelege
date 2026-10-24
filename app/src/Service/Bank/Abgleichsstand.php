<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Service\Bank\AbgleichAnzeige\Beleg;
use App\Service\Bank\AbgleichAnzeige\Buchung;

/**
 * One matching run for the page (M10-2, issue #66): the plan plus the
 * decrypted receipts and bookings it refers to, by id - in memory of this
 * request only.
 */
final readonly class Abgleichsstand
{
    /**
     * @param array<int, Beleg> $belege by invoice id
     * @param array<int, Buchung> $buchungen by transaction id
     */
    public function __construct(
        public Abgleichsplan $plan,
        public array $belege,
        public array $buchungen,
    ) {
    }
}
