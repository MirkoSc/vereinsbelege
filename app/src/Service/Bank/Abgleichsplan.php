<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What Punktebewertung::planen() decided (M10-2, issue #66): the pairs
 * allocated by themselves and, below them, the suggestions - best first.
 */
final readonly class Abgleichsplan
{
    /**
     * @param list<Bewertung> $auto
     * @param list<Bewertung> $vorschlaege
     */
    public function __construct(
        public array $auto,
        public array $vorschlaege,
    ) {
    }
}
