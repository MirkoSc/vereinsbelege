<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Allocation;
use App\Domain\ZuordnungMethode;

/**
 * One allocation of a booking in the Kassenprüfung (issue #74): how much of
 * the booking it covers, how it came about, and the receipt - null when the
 * receipt lies outside the reader's scope (an external auditor sees that a
 * receipt is allocated, not which one).
 */
final readonly class Pruefzuordnung
{
    public function __construct(
        public Allocation $zuordnung,
        public ?Pruefbeleg $beleg,
    ) {
    }

    public function vonHand(): bool
    {
        return $this->zuordnung->method === ZuordnungMethode::Manuell;
    }

    public function bezeichnung(): string
    {
        return ($this->beleg?->bezeichnung() ?? 'Beleg außerhalb Ihres Zugriffsbereichs')
            . ' (' . $this->zuordnung->method->label() . ')';
    }
}
