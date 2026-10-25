<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `payment_run` row as stored (issue #85, migrations/042_payment_run.sql):
 * plaintext structure, the sealed data key and the ciphertext, plus two
 * counts the query derives - how many receipts the run holds, how many of
 * them are still open (not settled) and how many got an allocation since
 * the run was created (part of its money has gone out).
 */
final readonly class ZahllaufRecord
{
    public function __construct(
        public int $id,
        public int $bankAccountId,
        public ZahllaufStatus $status,
        public \DateTimeImmutable $executionDate,
        public string $dekSealed,
        public string $dataEnc,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $discardedAt,
        public int $anzahlBelege,
        public int $offeneBelege,
        public int $zugeordneteBelege = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['bank_account_id'],
            ZahllaufStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['execution_date']),
            (string) $row['dek_sealed'],
            (string) $row['data_enc'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            new \DateTimeImmutable((string) $row['created_at']),
            $row['discarded_at'] === null ? null : new \DateTimeImmutable((string) $row['discarded_at']),
            (int) $row['anzahl_belege'],
            (int) $row['offene_belege'],
            (int) ($row['zugeordnete_belege'] ?? 0),
        );
    }
}
