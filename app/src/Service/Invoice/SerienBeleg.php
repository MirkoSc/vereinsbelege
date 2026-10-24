<?php

declare(strict_types=1);

namespace App\Service\Invoice;

/**
 * One checked receipt of a supplier as series detection sees it (issue
 * #57/M8-1): id, date and the decrypted gross amount in cents - only ever
 * in memory of a session request.
 */
final readonly class SerienBeleg
{
    public function __construct(
        public int $invoiceId,
        public \DateTimeImmutable $datum,
        public int $brutto,
        public string $waehrung,
    ) {
    }
}
