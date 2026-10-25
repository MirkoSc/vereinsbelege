<?php

declare(strict_types=1);

namespace App\Service\Report;

/** The page "Wohin fließt das Geld": one table per dimension. */
final readonly class GeldflussErgebnis
{
    /**
     * @param array<string, GeldflussTabelle> $tabellen by Dimension value
     */
    public function __construct(
        /** The part of the period the reader may see; null = none of it. */
        public ?Zeitraum $sichtbar,
        public array $tabellen,
        public int $unlesbar = 0,
        public int $fremdwaehrung = 0,
    ) {
    }
}
