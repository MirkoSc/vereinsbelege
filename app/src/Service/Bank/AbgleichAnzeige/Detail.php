<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

use App\Domain\Zahlungsstand;

/**
 * The selected receipt of the matching page (M10-4, issue #68): its
 * payment state, its allocations and - while it expects a booking and has
 * something open - the bookings that could take it, best first.
 */
final readonly class Detail
{
    /**
     * @param list<ZuordnungZeile> $zuordnungen
     * @param list<Kandidat> $kandidaten at most Abgleich::KANDIDATEN_MAX
     * @param int $kandidatenGesamt after the filters, before the cut
     */
    public function __construct(
        public Beleg $beleg,
        public Zahlungsstand $stand,
        public bool $nimmtZuordnungAn,
        public array $zuordnungen,
        public array $kandidaten,
        public int $kandidatenGesamt,
    ) {
    }
}
