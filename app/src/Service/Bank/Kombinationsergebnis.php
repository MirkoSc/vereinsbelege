<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What App\Service\Bank\Kombinationssuche found (M10-3, issue #67).
 */
final readonly class Kombinationsergebnis
{
    /**
     * @param list<list<int>> $treffer candidate ids per combination, smallest combinations first
     * @param bool $vollstaendig false when a bound (candidates, steps, hits) cut the search short
     */
    public function __construct(
        public array $treffer,
        public bool $vollstaendig,
    ) {
    }
}
