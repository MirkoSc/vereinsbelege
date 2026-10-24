<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

use App\Service\Bank\Bewertung;

/**
 * A booking that could take the selected receipt (M10-4, issue #68), with
 * its points - null when no signal fired - and whether the user discarded
 * the pair as a suggestion. $betrag is what the form proposes:
 * min(open receipt, open booking).
 */
final readonly class Kandidat
{
    public function __construct(
        public Buchung $buchung,
        public ?Bewertung $bewertung,
        public bool $verworfen,
        public int $betrag,
    ) {
    }
}
