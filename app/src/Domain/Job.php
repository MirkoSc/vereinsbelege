<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the `job` table. `state` is decoded JSON and carries ids and
 * counters only - the table is plaintext.
 */
final readonly class Job
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public int $id,
        public string $typ,
        public string $refType,
        public ?int $refId,
        public JobExecutor $executor,
        public JobStatus $status,
        public string $step,
        public array $state,
        public int $attempts,
        public ?string $lastError,
        public ?string $lockedBy,
        public ?\DateTimeImmutable $lockedUntil,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $state = isset($row['state']) && is_string($row['state'])
            ? json_decode($row['state'], true, flags: JSON_THROW_ON_ERROR)
            : [];

        return new self(
            id: (int) $row['id'],
            typ: (string) $row['typ'],
            refType: (string) $row['ref_type'],
            refId: $row['ref_id'] === null ? null : (int) $row['ref_id'],
            executor: JobExecutor::from((string) $row['executor']),
            status: JobStatus::from((string) $row['status']),
            step: (string) $row['step'],
            state: is_array($state) ? $state : [],
            attempts: (int) $row['attempts'],
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
            lockedBy: $row['locked_by'] === null ? null : (string) $row['locked_by'],
            lockedUntil: $row['locked_until'] === null ? null : new \DateTimeImmutable((string) $row['locked_until']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    public function isLockedAt(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }
}
