<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\InvoiceDirection;

/**
 * What the export path pattern of one receipt is built from (issue
 * #75/M12-1, docs/spec/05-auswertung-und-export.md section 2) - already
 * decrypted, so it only ever exists inside a session that holds the vault.
 * The ZIP export (M12-2) fills it from `invoice`, `supplier` and `category`;
 * App\Service\Export\PfadMuster turns it into folder and file names.
 *
 * `$kasse` says whether the receipt was paid in cash; it fills `{kasse}`.
 * Amounts are integer cents (CLAUDE.md section 5).
 */
final readonly class BelegPfadDaten
{
    public function __construct(
        public \DateTimeImmutable $datum,
        public InvoiceDirection $richtung,
        public ?string $lieferant = null,
        public ?string $kategorie = null,
        public string $nr = '',
        public int $betragCent = 0,
        public string $waehrung = 'EUR',
        public bool $kasse = false,
    ) {
    }
}
