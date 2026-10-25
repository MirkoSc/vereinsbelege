<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * A "Top N" list (e.g. Top 20 suppliers): the largest groups, the rest
 * summed up, and the lines without an assigned key on their own.
 */
final readonly class Rangliste
{
    /**
     * @param list<Gruppe> $top
     */
    public function __construct(
        public array $top,
        /** Everything with a key beyond the top N, summed; null when nothing is left. */
        public ?Summen $uebrige,
        /** The lines without a key ("ohne Lieferant"); null when there are none. */
        public ?Summen $ohne,
    ) {
    }
}
