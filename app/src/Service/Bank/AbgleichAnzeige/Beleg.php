<?php

declare(strict_types=1);

namespace App\Service\Bank\AbgleichAnzeige;

use App\Domain\InvoiceData;
use App\Domain\InvoiceRecord;

/** A receipt as the matching page shows it (M10-2, issue #66) - decrypted, this request only. */
final readonly class Beleg
{
    public function __construct(
        public InvoiceRecord $record,
        public InvoiceData $daten,
        public string $lieferant,
        public int $offen,
    ) {
    }
}
