<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\InvoiceData;
use App\Domain\InvoiceRecord;

/**
 * A captured receipt opened with the vault (issue #37/M6-3): the stored row
 * and its decrypted data. Only exists inside a request whose session has
 * the vault unlocked.
 */
final readonly class Invoice
{
    public function __construct(
        public InvoiceRecord $record,
        public InvoiceData $data,
    ) {
    }
}
