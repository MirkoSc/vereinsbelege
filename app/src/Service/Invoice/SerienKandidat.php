<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\SeriesPeriod;

/**
 * What series detection found (issue #57/M8-1): the rhythm, the receipts
 * that form the series (oldest first), the newest amount and the date the
 * next receipt is expected.
 */
final readonly class SerienKandidat
{
    /**
     * @param list<int> $invoiceIds oldest first
     */
    public function __construct(
        public SeriesPeriod $periode,
        public array $invoiceIds,
        public int $letzterBetrag,
        public string $waehrung,
        public \DateTimeImmutable $letztesDatum,
        public \DateTimeImmutable $naechsteFaelligkeit,
    ) {
    }
}
