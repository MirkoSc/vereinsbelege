<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

/**
 * One page of each side of the matching page (M10-4, issue #68): open
 * receipts | bookings without (enough) receipt, after the filters - with
 * the page actually shown (at most the last one).
 */
final readonly class Liste
{
    /**
     * @param list<Beleg> $belege this page
     * @param list<Buchung> $buchungen this page
     */
    public function __construct(
        public array $belege,
        public int $belegeGesamt,
        public int $seiteBelege,
        public array $buchungen,
        public int $buchungenGesamt,
        public int $seiteBuchungen,
    ) {
    }
}
