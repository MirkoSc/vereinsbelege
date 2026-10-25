<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Sphere;

/** One sphere's sums; `sphaere` null = the lines without any sphere. */
final readonly class SphaerenZeile
{
    public function __construct(
        public ?Sphere $sphaere,
        public Summen $summen,
        /** Lines of this sphere that took it from their category's default. */
        public int $ausKategorie = 0,
    ) {
    }

    public function name(): string
    {
        return $this->sphaere?->label() ?? 'ohne Sphäre';
    }
}
