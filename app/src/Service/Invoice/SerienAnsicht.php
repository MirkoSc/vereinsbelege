<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;

/**
 * A recurring series decrypted for its pages (issue #57/M8-1). The list
 * leaves $mitglieder and $weitere empty; the detail page fills them: the
 * receipts of the series and the supplier's other checked receipts that
 * belong to no series and can be added.
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
    ) {
    }

    /** The label a person gave it, else the supplier's name. */
    public function titel(): string
    {
        return $this->daten->label !== '' ? $this->daten->label : $this->lieferant;
    }
}
