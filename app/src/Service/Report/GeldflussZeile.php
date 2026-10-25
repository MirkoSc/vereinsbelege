<?php

declare(strict_types=1);

namespace App\Service\Report;

/** One group of "Wohin fließt das Geld": a category or cost center and its amount. */
final readonly class GeldflussZeile
{
    public function __construct(
        /** The category / cost center id, null = none assigned. */
        public ?int $schluessel,
        public string $name,
        /** Integer cents on the chosen side; negative when credit notes outweigh. */
        public int $betrag,
        public int $anzahl,
        /** Share of the total in tenths of a percent; null when it has no meaning (negative amount or total). */
        public ?int $anteilPromille,
        /** Bar length relative to the largest group, in tenths of a percent (0 … 1000). */
        public int $balkenPromille,
    ) {
    }

    /** Drilldown key for a URL: the id, or `ohne`. */
    public function schluesselParameter(): string
    {
        return $this->schluessel === null ? 'ohne' : (string) $this->schluessel;
    }
}
