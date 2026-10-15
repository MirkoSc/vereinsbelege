<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `archive_import` row (migrations/028_archive_import.sql, M12-3,
 * issue #77). Plaintext structure plus the sealed DEK and the encrypted
 * report.
 */
final readonly class ArchiveImportRecord
{
    public function __construct(
        public int $id,
        public string $uploadId,
        public ArchiveImportStatus $status,
        public string $namePattern,
        public int $total,
        public int $nextIndex,
        public ArchiveImportStats $stats,
        public string $dekSealed,
        public ?string $reportEnc,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);
        $stats = $row['stats'] === null ? [] : json_decode((string) $row['stats'], true, 4, JSON_THROW_ON_ERROR);

        return new self(
            id: (int) $row['id'],
            uploadId: (string) $row['upload_id'],
            status: ArchiveImportStatus::from((string) $row['status']),
            namePattern: (string) $row['name_pattern'],
            total: (int) $row['total'],
            nextIndex: (int) $row['next_index'],
            stats: ArchiveImportStats::fromArray(is_array($stats) ? $stats : []),
            dekSealed: (string) $row['dek_sealed'],
            reportEnc: $row['report_enc'] === null ? null : (string) $row['report_enc'],
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            startedAt: $datum($row['started_at']),
            finishedAt: $datum($row['finished_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
