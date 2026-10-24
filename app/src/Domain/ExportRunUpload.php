<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `export_run_upload` row (migrations/033_nextcloud.sql, issue #95):
 * where the Nextcloud filing of an export run stands. Plaintext structure
 * only - a cursor and counters.
 */
final readonly class ExportRunUpload
{
    public function __construct(
        public int $exportRunId,
        public NextcloudAblageStatus $status,
        public int $nextIndex,
        public int $total,
        public string $planHash,
        public int $hochgeladen,
        public int $vorhanden,
        public int $umbenannt,
        public ?int $startedBy,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            exportRunId: (int) $row['export_run_id'],
            status: NextcloudAblageStatus::from((string) $row['status']),
            nextIndex: (int) $row['next_index'],
            total: (int) $row['total'],
            planHash: (string) $row['plan_hash'],
            hochgeladen: (int) $row['hochgeladen'],
            vorhanden: (int) $row['vorhanden'],
            umbenannt: (int) $row['umbenannt'],
            startedBy: $row['started_by'] === null ? null : (int) $row['started_by'],
            startedAt: new \DateTimeImmutable((string) $row['started_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            finishedAt: $row['finished_at'] === null ? null : new \DateTimeImmutable((string) $row['finished_at']),
        );
    }
}
