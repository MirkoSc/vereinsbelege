<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * One count on the dashboard (issue #72): "3 Belege in Prüfung" with the
 * page that lists them. Counts are structure (status fields), never vault
 * data, so they show without an unlocked vault.
 */
final readonly class Zaehlwert
{
    public function __construct(
        public string $bezeichnung,
        public int $anzahl,
        /** The list behind the count; null when the account may not open it. */
        public ?string $pfad,
        /** A short addition, e.g. receipts from before the matching. */
        public ?string $zusatz = null,
    ) {
    }
}
