<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * One receipt as the series page shows it (issue #57/M8-1): decrypted
 * amount, date, the document it opens, and whether it is still checked.
 */
final readonly class SerienPosten
{
    public function __construct(
        public int $invoiceId,
        public int $documentId,
        public \DateTimeImmutable $datum,
        public int $brutto,
        public string $waehrung,
        public bool $geprueft,
    ) {
    }
}
