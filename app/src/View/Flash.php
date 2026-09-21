<?php

declare(strict_types=1);

namespace App\View;

/**
 * A one-off message shown after a redirect (post/redirect/get).
 *
 * Never fachliche Klartextdaten: flashes live in the session store on disk
 * and are written before the redirect, so amounts, names or supplier data
 * have no business in one (CLAUDE.md section 4). "Beleg gespeichert." yes,
 * "Beleg über 42,00 € von ... gespeichert." no.
 */
final readonly class Flash
{
    public function __construct(
        public string $text,
        public FlashArt $art = FlashArt::Ok,
    ) {
    }
}
