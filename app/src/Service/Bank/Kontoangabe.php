<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The account a statement belongs to (:25:). German banks send either
 * "BLZ/Kontonummer" (optionally followed by the currency) or the IBAN.
 * Matching it to one of the club's accounts is the import's job (M9-4).
 */
final readonly class Kontoangabe
{
    public function __construct(
        public string $roh,
        public ?string $iban,
        public ?string $blz,
        public ?string $kontonummer,
    ) {
    }
}
