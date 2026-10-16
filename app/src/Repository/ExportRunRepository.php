<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ExportRun;
use App\Domain\ExportRunStatus;

/**
 * The tables `export_run` and `export_run_document`
 * (migrations/029_export_run.sql, M12-4, issue #179). SQL only - what a
 * run holds is worked out by App\Service\Export\ZipExport.
 *
 * Every state change names the state it comes from in its WHERE clause and
 * reports through the row count whether it happened, like
 * App\Repository\ArchiveImportRepository: a second tab or a repeated
 * request never confirms or resets a run twice.
 */
final readonly class ExportRunRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * A new `offen` run with its documents, in one transaction.
     *
     * @param array<string, string> $filter
     * @param list<int> $documentIds
     */
    public function anlegen(array $filter, array $documentIds, int $belege, int $dateien, ?int $userId, \DateTimeImmutable $now): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO export_run (status, filter, belege, dateien, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            );
            $stmt->bindValue(1, ExportRunStatus::Offen->value);
            $stmt->bindValue(2, json_encode($filter, JSON_THROW_ON_ERROR));
            $stmt->bindValue(3, $belege, \PDO::PARAM_INT);
            $stmt->bindValue(4, $dateien, \PDO::PARAM_INT);
            $stmt->bindValue(5, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindValue(6, $now->format(self::FORMAT));
            $stmt->execute();
            $id = (int) $this->pdo->lastInsertId();

            $zuordnung = $this->pdo->prepare('INSERT INTO export_run_document (export_run_id, document_id) VALUES (?, ?)');
            foreach (array_unique($documentIds) as $documentId) {
                $zuordnung->execute([$id, $documentId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function find(int $id): ?ExportRun
    {
        $stmt = $this->pdo->prepare('SELECT * FROM export_run WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : ExportRun::fromRow($row);
    }

    /**
     * The latest runs, newest first - all of them, or only those $userId
     * created.
     *
     * @return list<ExportRun>
     */
    public function letzte(?int $userId, int $limit): array
    {
        $sql = 'SELECT * FROM export_run' . ($userId === null ? '' : ' WHERE created_by = ?');
        $stmt = $this->pdo->prepare(sprintf('%s ORDER BY id DESC LIMIT %d', $sql, max(1, $limit)));
        $stmt->execute($userId === null ? [] : [$userId]);

        return array_map(ExportRun::fromRow(...), $stmt->fetchAll());
    }

    /** `offen` → `bestaetigt`. False when the run is not open (any more). */
    public function bestaetigen(int $id, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE export_run SET status = ?, confirmed_by = ?, confirmed_at = ? WHERE id = ? AND status = ?',
        );
        $stmt->execute([ExportRunStatus::Bestaetigt->value, $userId, $now->format(self::FORMAT), $id, ExportRunStatus::Offen->value]);

        return $stmt->rowCount() === 1;
    }

    /** `bestaetigt` → `zurueckgesetzt`. False when the run is not confirmed. */
    public function zuruecksetzen(int $id, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE export_run SET status = ?, reset_by = ?, reset_at = ? WHERE id = ? AND status = ?',
        );
        $stmt->execute([ExportRunStatus::Zurueckgesetzt->value, $userId, $now->format(self::FORMAT), $id, ExportRunStatus::Bestaetigt->value]);

        return $stmt->rowCount() === 1;
    }
}
