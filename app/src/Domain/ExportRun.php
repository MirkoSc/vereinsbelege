<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `export_run` row (migrations/029_export_run.sql, M12-4, issue #179).
 * Plaintext structure only: the filter is ids and dates.
 */
final readonly class ExportRun
{
    /**
     * @param array<string, string> $filter form fields of
     *        App\Service\Export\ExportFilter::alsFelder()
     */
    public function __construct(
        public int $id,
        public ExportRunStatus $status,
        public array $filter,
        public int $belege,
        public int $dateien,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
        public ?int $confirmedBy,
        public ?\DateTimeImmutable $confirmedAt,
        public ?int $resetBy,
        public ?\DateTimeImmutable $resetAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);
        $id = static fn(mixed $wert): ?int => $wert === null ? null : (int) $wert;
        $filter = json_decode((string) $row['filter'], true, 2, JSON_THROW_ON_ERROR);

        return new self(
            id: (int) $row['id'],
            status: ExportRunStatus::from((string) $row['status']),
            filter: is_array($filter) ? array_map(strval(...), array_filter($filter, is_scalar(...))) : [],
            belege: (int) $row['belege'],
            dateien: (int) $row['dateien'],
            createdBy: $id($row['created_by']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            confirmedBy: $id($row['confirmed_by']),
            confirmedAt: $datum($row['confirmed_at']),
            resetBy: $id($row['reset_by']),
            resetAt: $datum($row['reset_at']),
        );
    }
}
