<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ArchiveImportRecord;
use App\Domain\ArchiveImportStats;
use App\Domain\ArchiveImportStatus;

/**
 * The `archive_import` table (migrations/028_archive_import.sql, M12-3,
 * issue #77). SQL only - reading the ZIP and writing the documents live in
 * App\Service\Archiv\ArchivImport.
 *
 * Every state change names the state it comes from in its WHERE clause and
 * reports through the row count whether it happened, like
 * App\Repository\BankImportRepository: a second tab or a repeated request
 * never moves an import twice.
 */
final readonly class ArchiveImportRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(string $uploadId, string $namePattern, int $total, string $dekSealed, ?int $createdBy, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO archive_import (upload_id, status, name_pattern, total, next_index, stats, dek_sealed, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $uploadId);
        $stmt->bindValue(2, ArchiveImportStatus::Vorschau->value);
        $stmt->bindValue(3, $namePattern);
        $stmt->bindValue(4, $total, \PDO::PARAM_INT);
        $stmt->bindValue(5, json_encode(new ArchiveImportStats()->toArray(), JSON_THROW_ON_ERROR));
        $stmt->bindValue(6, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(7, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(8, $now->format(self::FORMAT));
        $stmt->bindValue(9, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?ArchiveImportRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM archive_import WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : ArchiveImportRecord::fromRow($row);
    }

    /** The import an upload already became - a repeated closing request finds it. */
    public function idFuerUpload(string $uploadId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM archive_import WHERE upload_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$uploadId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The latest imports, newest first - the history on the import page.
     *
     * @return list<ArchiveImportRecord>
     */
    public function recent(int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM archive_import ORDER BY id DESC LIMIT %d', max(1, $limit)));
        $stmt->execute();

        return array_map(ArchiveImportRecord::fromRow(...), $stmt->fetchAll());
    }

    /** The pattern of a preview - only while nothing is written. */
    public function setzeMuster(int $id, string $namePattern, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE archive_import SET name_pattern = ?, updated_at = ? WHERE id = ? AND status = ?');
        $stmt->execute([$namePattern, $now->format(self::FORMAT), $id, ArchiveImportStatus::Vorschau->value]);

        return $stmt->rowCount() === 1;
    }

    /** `vorschau` → `laeuft` with the pattern the user confirmed. */
    public function starte(int $id, string $namePattern, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE archive_import SET status = ?, name_pattern = ?, next_index = 0, started_at = ?, updated_at = ? WHERE id = ? AND status = ?',
        );
        $stmt->execute([
            ArchiveImportStatus::Laeuft->value,
            $namePattern,
            $now->format(self::FORMAT),
            $now->format(self::FORMAT),
            $id,
            ArchiveImportStatus::Vorschau->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Moves the cursor from exactly $von to $von + 1. False when another
     * request already moved it (or the import is no longer running): the
     * caller then rolls back what it wrote in the same transaction. Called
     * first in that transaction - the UPDATE takes the row lock, so a second
     * step on the same import waits here until the first one has committed,
     * and then loses.
     */
    public function advance(int $id, int $von, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE archive_import SET next_index = ?, updated_at = ? WHERE id = ? AND status = ? AND next_index = ?',
        );
        $stmt->execute([$von + 1, $now->format(self::FORMAT), $id, ArchiveImportStatus::Laeuft->value, $von]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The counts and - when it changed - the report, inside the transaction
     * of advance().
     */
    public function setzeStand(int $id, ArchiveImportStats $stats, ?string $reportEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE archive_import SET stats = ?, report_enc = COALESCE(?, report_enc) WHERE id = ?');
        $stmt->bindValue(1, json_encode($stats->toArray(), JSON_THROW_ON_ERROR));
        $stmt->bindValue(2, $reportEnc, $reportEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** `laeuft` → `fertig` once the cursor is through. */
    public function finish(int $id, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE archive_import SET status = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ? AND next_index >= total',
        );
        $stmt->execute([ArchiveImportStatus::Fertig->value, $now->format(self::FORMAT), $now->format(self::FORMAT), $id, ArchiveImportStatus::Laeuft->value]);

        return $stmt->rowCount() === 1;
    }

    /** `vorschau`/`laeuft` → `abgebrochen`. */
    public function abbrechen(int $id, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE archive_import SET status = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status IN (?, ?)',
        );
        $stmt->execute([
            ArchiveImportStatus::Abgebrochen->value,
            $now->format(self::FORMAT),
            $now->format(self::FORMAT),
            $id,
            ArchiveImportStatus::Vorschau->value,
            ArchiveImportStatus::Laeuft->value,
        ]);

        return $stmt->rowCount() === 1;
    }
}
