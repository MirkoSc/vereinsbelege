<?php

declare(strict_types=1);

namespace App\Service\Report;

/** The page "Sphären" (issue #84): one row per sphere, the lines without one last. */
final readonly class SphaerenErgebnis
{
    /**
     * @param list<SphaerenZeile> $zeilen
     */
    public function __construct(
        /** The part of the period the reader may see; null = none of it. */
        public ?Zeitraum $sichtbar,
        public array $zeilen,
        public Summen $summe,
        public int $unlesbar = 0,
        public int $fremdwaehrung = 0,
    ) {
    }

    /** How many lines have no sphere at all - still to be assigned. */
    public function ohneSphaere(): int
    {
        foreach ($this->zeilen as $zeile) {
            if ($zeile->sphaere === null) {
                return $zeile->summen->anzahl;
            }
        }

        return 0;
    }

    /** How many lines took their sphere from their category's default. */
    public function ausKategorie(): int
    {
        return array_sum(array_map(static fn(SphaerenZeile $z): int => $z->ausKategorie, $this->zeilen));
    }
}
