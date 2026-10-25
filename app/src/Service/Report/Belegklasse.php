<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\InvoiceType;

/**
 * The plaintext structure of a checked receipt a report needs - no vault
 * data. On the booking basis an allocated part of a booking takes its
 * category, cost center, supplier and sphere from here, so a booking's
 * receipts are classified without decrypting them.
 */
final readonly class Belegklasse
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $datum,
        public InvoiceDirection $richtung,
        public InvoiceType $typ,
        public ?int $kategorieId = null,
        public ?int $kostenstelleId = null,
        public ?int $lieferantId = null,
        public ?string $sphaere = null,
    ) {
    }

    public static function ausRecord(InvoiceRecord $record, ?string $sphaere): self
    {
        return new self(
            $record->id,
            $record->invoiceDate,
            $record->direction,
            $record->docType,
            $record->categoryId,
            $record->costCenterId,
            $record->supplierId,
            $sphaere === '' ? null : $sphaere,
        );
    }
}
