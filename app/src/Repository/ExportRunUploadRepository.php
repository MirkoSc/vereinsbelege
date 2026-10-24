<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ExportRunUpload;
use App\Domain\NextcloudAblageStatus;

/**
 * The table `export_run_upload` (migrations/032_nextcloud.sql, issue #95):
 * the step chain that files an export run into the Nextcloud. SQL only -
 * the chain itself is App\Service\Export\Nextcloud\NextcloudAblage.
 *
 * Every step names the cursor it read in its WHERE clause (like
 * App\Repository\ArchiveImportRepository): two tabs driving the same chain
 * never count a file twice - the second one's update simply does not
 * happen.
 */
final readonly class ExportRunUploadRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $exportRunId): ?ExportRunUpload
    {
        $stmt = $this->pdo->prepare('SELECT * FROM export_run_upload WHERE export_run_id = ?');
        $stmt->execute([$exportRunId]);
        $row = $stmt->fetch();

        return $row === false ? null : ExportRunUpload::fromRow($row);
    }

    /**
     * Starts the chain from the first file - a new row, or the old one of
     * this run reset (a second filing skips what is there already).
     */
    public function starten(int $exportRunId, int $total, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO export_run_upload (export_run_id, status, next_index, total, hochgeladen, vorhanden, umbenannt, started_by, started_at, updated_at, finished_at)
             VALUES (?, ?, 0, ?, 0, 0, 0, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE status = VALUES(status), next_index = 0, total = VALUES(total), hochgeladen = 0, vorhanden = 0,
                 umbenannt = 0, started_by = VALUES(started_by), started_at = VALUES(started_at), updated_at = VALUES(updated_at), finished_at = NULL',
        );
        $stmt->bindValue(1, $exportRunId, \PDO::PARAM_INT);
        $stmt->bindValue(2, NextcloudAblageStatus::Laeuft->value);
        $stmt->bindValue(3, $total, \PDO::PARAM_INT);
        $stmt->bindValue(4, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * One file done: the cursor moves from $vonIndex to the next file and
     * the matching counter grows. False when another request moved the
     * cursor first (or the chain is no longer running).
     *
     * @param 'hochgeladen'|'vorhanden'|'umbenannt' $zaehler
     */
    public function weiter(int $exportRunId, int $vonIndex, string $zaehler, \DateTimeImmutable $now): bool
    {
        $spalte = match ($zaehler) {
            'hochgeladen' => 'hochgeladen',
            'vorhanden' => 'vorhanden',
            'umbenannt' => 'umbenannt',
        };
        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE export_run_upload SET next_index = next_index + 1, %1$s = %1$s + 1, updated_at = ?
             WHERE export_run_id = ? AND next_index = ? AND status = ?',
            $spalte,
        ));
        $stmt->execute([$now->format(self::FORMAT), $exportRunId, $vonIndex, NextcloudAblageStatus::Laeuft->value]);

        return $stmt->rowCount() === 1;
    }

    /** `laeuft` → `fertig`. False when the chain was not running (any more). */
    public function abschliessen(int $exportRunId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE export_run_upload SET status = ?, updated_at = ?, finished_at = ? WHERE export_run_id = ? AND status = ?',
        );
        $stmt->execute([
            NextcloudAblageStatus::Fertig->value,
            $now->format(self::FORMAT),
            $now->format(self::FORMAT),
            $exportRunId,
            NextcloudAblageStatus::Laeuft->value,
        ]);

        return $stmt->rowCount() === 1;
    }
}
