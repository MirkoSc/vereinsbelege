<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;

/**
 * A recurring series decrypted for its pages (issue #57/M8-1). The list
 * leaves $weitere empty and fills $kennzahlen (issue #58/M8-2: newest
 * receipt, yearly projection, trend, overdue); the detail page fills
 * $mitglieder and $weitere: the receipts of the series and the supplier's
 * other checked receipts that belong to no series and can be added.
 */
final readonly class SerienAnsicht
{
    /**
     * @param list<SerienPosten> $mitglieder
     * @param list<SerienPosten> $weitere
     */
    public function __construct(
        public RecurringSeriesRecord $record,
        public SeriesData $daten,
        public string $lieferant,
        public int $anzahl,
        public array $mitglieder = [],
        public array $weitere = [],
        public ?SerienKennzahlen $kennzahlen = null,
    ) {
    }

    /** The label a person gave it, else the supplier's name. */
    public function titel(): string
    {
        return $this->daten->label !== '' ? $this->daten->label : $this->lieferant;
    }
}
