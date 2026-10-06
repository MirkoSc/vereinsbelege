<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BalanceCheck;
use App\Domain\BankImportRecord;
use App\Domain\BankImportStats;
use App\Domain\BankImportStatus;

/**
 * The `bank_import` table (migrations/025_bank_import.sql, M9-4, issue #62,
 * docs/spec/04-bank-und-abgleich.md section 4). SQL only - reading the file
 * and the rules live in App\Service\Bank\KontoauszugImport.
 *
 * Every state change names the state it comes from in its WHERE clause and
 * reports through the row count whether it happened: a second tab, a
 * repeated request or the cleanup cron can never move an import twice.
 */
final readonly class BankImportRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        ?int $accountId,
        string $format,
        int $fileBlobId,
        ?string $sourceBi,
        ?\DateTimeImmutable $periodFrom,
        ?\DateTimeImmutable $periodTo,
        ?int $createdBy,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bank_import (account_id, format, file_blob_id, source_bi, status, next_index, period_from, period_to, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $accountId, $accountId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $format);
        $stmt->bindValue(3, $fileBlobId, \PDO::PARAM_INT);
        $stmt->bindValue(4, $sourceBi, $sourceBi === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(5, BankImportStatus::Vorschau->value);
        $stmt->bindValue(6, $periodFrom?->format('Y-m-d'));
        $stmt->bindValue(7, $periodTo?->format('Y-m-d'));
        $stmt->bindValue(8, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(9, $now->format(self::FORMAT));
        $stmt->bindValue(10, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?BankImportRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_import WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The latest imports, newest first - the history on the import page.
     *
     * @return list<BankImportRecord>
     */
    public function recent(int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM bank_import ORDER BY id DESC LIMIT %d', max(1, $limit)));
        $stmt->execute();

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** "Welches Konto?" - only while the import is still a preview. */
    public function setAccount(int $id, int $accountId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE bank_import SET account_id = ?, updated_at = ? WHERE id = ? AND status = ?');
        $stmt->execute([$accountId, $now->format(self::FORMAT), $id, BankImportStatus::Vorschau->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Confirms a preview: `vorschau` → `laeuft`, with the counts and the
     * balance check the user saw. Needs an account.
     */
    public function start(int $id, ?int $userId, BankImportStats $stats, BalanceCheck $saldo, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_import SET status = ?, next_index = 0, stats = ?, balance_check = ?, imported_by = ?, imported_at = ?, updated_at = ?
             WHERE id = ? AND status = ? AND account_id IS NOT NULL',
        );
        $stmt->bindValue(1, BankImportStatus::Laeuft->value);
        $stmt->bindValue(2, json_encode($stats->toArray(), JSON_THROW_ON_ERROR));
        $stmt->bindValue(3, $saldo->value);
        $stmt->bindValue(4, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $id, \PDO::PARAM_INT);
        $stmt->bindValue(8, BankImportStatus::Vorschau->value);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Moves the step chain on from exactly $von to $bis. False when another
     * request already moved it (or the import is no longer running) - the
     * caller then rolls back what it wrote in the same transaction. The
     * UPDATE also takes the row lock, so a second step on the same import
     * waits here until the first one has committed.
     */
    public function advance(int $id, int $von, int $bis, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_import SET next_index = ?, updated_at = ? WHERE id = ? AND status = ? AND next_index = ?',
        );
        $stmt->execute([$bis, $now->format(self::FORMAT), $id, BankImportStatus::Laeuft->value, $von]);

        return $stmt->rowCount() === 1;
    }

    public function finish(int $id, BankImportStats $stats, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE bank_import SET status = ?, stats = ?, updated_at = ? WHERE id = ? AND status = ?');
        $stmt->execute([
            BankImportStatus::Fertig->value,
            json_encode($stats->toArray(), JSON_THROW_ON_ERROR),
            $now->format(self::FORMAT),
            $id,
            BankImportStatus::Laeuft->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Removes a preview. A confirmed import stays - its bookings point at it
     * and its file is the original (docs/spec/04-bank-und-abgleich.md
     * section 4).
     */
    public function deletePreview(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM bank_import WHERE id = ? AND status = ?');
        $stmt->execute([$id, BankImportStatus::Vorschau->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The account picked for the last finished import of a file with this
     * account line - "Welches Konto?" is asked once per line, not per file.
     */
    public function accountForSource(string $sourceBi): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT account_id FROM bank_import WHERE source_bi = ? AND status = ? AND account_id IS NOT NULL ORDER BY id DESC LIMIT 1',
        );
        $stmt->bindValue(1, $sourceBi, \PDO::PARAM_LOB);
        $stmt->bindValue(2, BankImportStatus::Fertig->value);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Previews nobody confirmed or discarded, created before $vor - for
     * App\Service\Cron\BankImportCleanupTask.
     *
     * @return list<array{id: int, file_blob_id: int}>
     */
    public function previewsBefore(\DateTimeImmutable $vor): array
    {
        $stmt = $this->pdo->prepare('SELECT id, file_blob_id FROM bank_import WHERE status = ? AND created_at < ? ORDER BY id');
        $stmt->execute([BankImportStatus::Vorschau->value, $vor->format(self::FORMAT)]);

        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'file_blob_id' => (int) $row['file_blob_id']],
            $stmt->fetchAll(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): BankImportRecord
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);
        $stats = $row['stats'] === null ? null : json_decode((string) $row['stats'], true, 4, JSON_THROW_ON_ERROR);

        return new BankImportRecord(
            id: (int) $row['id'],
            accountId: $row['account_id'] === null ? null : (int) $row['account_id'],
            format: (string) $row['format'],
            fileBlobId: (int) $row['file_blob_id'],
            sourceBi: $row['source_bi'] === null ? null : (string) $row['source_bi'],
            status: BankImportStatus::from((string) $row['status']),
            nextIndex: (int) $row['next_index'],
            stats: is_array($stats) ? BankImportStats::fromArray($stats) : null,
            balanceCheck: $row['balance_check'] === null ? null : BalanceCheck::from((string) $row['balance_check']),
            periodFrom: $datum($row['period_from']),
            periodTo: $datum($row['period_to']),
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            importedBy: $row['imported_by'] === null ? null : (int) $row['imported_by'],
            importedAt: $datum($row['imported_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
