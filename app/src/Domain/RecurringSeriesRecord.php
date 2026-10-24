<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `recurring_series` row as stored (issue #57/M8-1,
 * migrations/036_recurring_series.sql): plaintext structure plus the
 * ciphertext of App\Domain\SeriesData.
 */
final readonly class RecurringSeriesRecord
{
    public function __construct(
        public int $id,
        public int $supplierId,
        public InvoiceDirection $direction,
        public SeriesPeriod $period,
        public SeriesStatus $status,
        public ?\DateTimeImmutable $nextExpected,
        public int $toleranceDays,
        public string $dekSealed,
        public string $dataEnc,
        public ?int $decidedBy,
        public ?\DateTimeImmutable $decidedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);

        return new self(
            id: (int) $row['id'],
            supplierId: (int) $row['supplier_id'],
            direction: InvoiceDirection::from((string) $row['direction']),
            period: SeriesPeriod::from((string) $row['period']),
            status: SeriesStatus::from((string) $row['status']),
            nextExpected: $datum($row['next_expected']),
            toleranceDays: (int) $row['tolerance_days'],
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            decidedBy: $row['decided_by'] === null ? null : (int) $row['decided_by'],
            decidedAt: $datum($row['decided_at']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
