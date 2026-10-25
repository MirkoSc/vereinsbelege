<?php

declare(strict_types=1);

namespace App\Service\Report;

/** The single lines behind one group of "Wohin fließt das Geld". */
final readonly class GeldflussDrilldown
{
    /**
     * @param list<GeldflussPosten> $posten
     */
    public function __construct(
        /** The part of the period the reader may see; null = none of it. */
        public ?Zeitraum $sichtbar,
        /** The name of the group (category / cost center / "ohne …"). */
        public string $titel,
        public array $posten,
        public int $summe,
        public int $unlesbar = 0,
        public int $fremdwaehrung = 0,
    ) {
    }
}
