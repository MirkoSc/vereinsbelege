<?php

declare(strict_types=1);

namespace App\Service\Report;

/** The groups of one dimension and their total, largest first, "ohne" last. */
final readonly class GeldflussTabelle
{
    /**
     * @param list<GeldflussZeile> $zeilen
     */
    public function __construct(
        public Dimension $dimension,
        public array $zeilen,
        public int $summe,
    ) {
    }
}
