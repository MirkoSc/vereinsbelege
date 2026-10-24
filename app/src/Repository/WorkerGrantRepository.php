<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\JobExecutor;
use App\Domain\JobStatus;

/**
 * The tables `worker_grant` and `worker_context` (issue #52/M7b-2,
 * docs/spec/07-worker.md section 3, migration 039): per-document data keys
 * and AI context, sealed to one worker's W_pub. Everything here is either
 * structural or sealed - nothing the web host could read.
 */
final readonly class WorkerGrantRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /** Stores or renews the grant of one blob for one worker. */
    public function grantSpeichern(int $workerId, int $documentId, int $blobId, string $sealedDek, \DateTimeImmutable $gueltigBis, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_grant (worker_id, document_id, blob_id, sealed_dek, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sealed_dek = VALUES(sealed_dek), expires_at = VALUES(expires_at), created_at = VALUES(created_at)',
        );
        $stmt->execute([$workerId, $documentId, $blobId, $sealedDek, $gueltigBis->format(self::FORMAT), $now->format(self::FORMAT)]);
    }

    public function kontextSpeichern(int $workerId, int $documentId, string $sealedPayload, \DateTimeImmutable $gueltigBis, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_context (worker_id, document_id, sealed_payload, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sealed_payload = VALUES(sealed_payload), expires_at = VALUES(expires_at), created_at = VALUES(created_at)',
        );
        $stmt->execute([$workerId, $documentId, $sealedPayload, $gueltigBis->format(self::FORMAT), $now->format(self::FORMAT)]);
    }

    /**
     * The sealed data key of a blob - only if this worker holds a grant for
     * it AND currently holds a running, unexpired claim on a job of the same
     * document. One query, so the two conditions cannot drift apart.
     *
     * @return ?array{document_id: int, sealed_dek: string}
     */
    public function grantFuerGehaltenenJob(int $workerId, int $blobId, string $lockPraefix, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.document_id, g.sealed_dek FROM worker_grant g
             WHERE g.worker_id = ? AND g.blob_id = ? AND g.expires_at > ?
               AND EXISTS (
                   SELECT 1 FROM job j
                   WHERE j.ref_type = ? AND j.ref_id = g.document_id AND j.executor = ? AND j.status = ?
                     AND j.locked_until > ? AND LEFT(j.locked_by, ?) = ?
               )
             LIMIT 1',
        );
        $jetzt = $now->format(self::FORMAT);
        $stmt->execute([
            $workerId,
            $blobId,
            $jetzt,
            'document',
            JobExecutor::Worker->value,
            JobStatus::Laeuft->value,
            $jetzt,
            strlen($lockPraefix),
            $lockPraefix,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? ['document_id' => (int) $row['document_id'], 'sealed_dek' => (string) $row['sealed_dek']] : null;
    }

    /**
     * Blob ids this worker holds a valid grant for, of one document.
     *
     * @return list<int>
     */
    public function blobIdsFuer(int $workerId, int $documentId, \DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare('SELECT blob_id FROM worker_grant WHERE worker_id = ? AND document_id = ? AND expires_at > ? ORDER BY blob_id');
        $stmt->execute([$workerId, $documentId, $now->format(self::FORMAT)]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function kontextFuer(int $workerId, int $documentId, \DateTimeImmutable $now): ?string
    {
        $stmt = $this->pdo->prepare('SELECT sealed_payload FROM worker_context WHERE worker_id = ? AND document_id = ? AND expires_at > ?');
        $stmt->execute([$workerId, $documentId, $now->format(self::FORMAT)]);
        $wert = $stmt->fetchColumn();

        return is_string($wert) ? $wert : null;
    }

    /** Everything of one document - after the last worker job of it ended. */
    public function loescheFuerDokument(int $documentId): int
    {
        return $this->loesche('document_id = ?', [$documentId]);
    }

    /** Everything of one worker - when it is unpaired. */
    public function loescheFuerWorker(int $workerId): int
    {
        return $this->loesche('worker_id = ?', [$workerId]);
    }

    /**
     * The cron's deadline: grants past `expires_at`, and grants of documents
     * on which no worker job waits or runs any more (a job that went back
     * to the browser/session route, issue #53, or one that ended without
     * complete/fail reaching the web host) once they are older than
     * $verwaistVor - so a grant written just before its job is not lost.
     */
    public function aufraeumen(\DateTimeImmutable $now, \DateTimeImmutable $verwaistVor): int
    {
        $ohneJob = sprintf(
            'created_at < ? AND NOT EXISTS (SELECT 1 FROM job j WHERE j.ref_type = %s AND j.ref_id = %%s.document_id AND j.executor = %s AND j.status IN (%s, %s))',
            $this->pdo->quote('document'),
            $this->pdo->quote(JobExecutor::Worker->value),
            $this->pdo->quote(JobStatus::Offen->value),
            $this->pdo->quote(JobStatus::Laeuft->value),
        );
        $anzahl = 0;
        foreach (['worker_grant', 'worker_context'] as $tabelle) {
            $stmt = $this->pdo->prepare(sprintf('DELETE FROM %1$s WHERE expires_at <= ? OR (%2$s)', $tabelle, sprintf($ohneJob, $tabelle)));
            $stmt->execute([$now->format(self::FORMAT), $verwaistVor->format(self::FORMAT)]);
            $anzahl += $stmt->rowCount();
        }

        return $anzahl;
    }

    /**
     * @param list<int> $params
     */
    private function loesche(string $bedingung, array $params): int
    {
        $anzahl = 0;
        foreach (['worker_grant', 'worker_context'] as $tabelle) {
            $stmt = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE %s', $tabelle, $bedingung));
            $stmt->execute($params);
            $anzahl += $stmt->rowCount();
        }

        return $anzahl;
    }
}
