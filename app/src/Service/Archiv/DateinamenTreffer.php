<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * What App\Service\Archiv\DateinamenMuster read from one file name (issue
 * #77/M12-3). Each value is null when the name did not carry it or when it
 * was not valid - an invalid date is never guessed into a valid one; the
 * reason then stands in `hinweise` (German, for the review page).
 */
final readonly class DateinamenTreffer
{
    /**
     * @param list<string> $hinweise
     */
    public function __construct(
        public ?string $lieferant = null,
        public ?\DateTimeImmutable $datum = null,
        public ?string $nr = null,
        public bool $ohneLieferant = false,
        public array $hinweise = [],
    ) {
    }
}
