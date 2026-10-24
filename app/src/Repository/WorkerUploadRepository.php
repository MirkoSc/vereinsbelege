<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\WorkerUpload;

/**
 * The table `worker_upload` (issue #52/M7b-2, migration 041): artifact
 * uploads the worker announced and has not committed yet. Shape data only;
 * the ciphertext waits in shared/var/tmp (App\Service\Upload\UploadService).
 */
final readonly class WorkerUploadRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function anlegen(WorkerUpload $upload, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_upload (artifact_id, worker_id, job_id, attempt, art, header, cipher_size, cipher_sha256, upload_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $upload->artifactId,
            $upload->workerId,
            $upload->jobId,
            $upload->attempt,
            $upload->art,
            $upload->header,
            $upload->cipherSize,
            $upload->cipherSha256,
            $upload->uploadId,
            $now->format(self::FORMAT),
        ]);
    }

    public function find(int $artifactId): ?WorkerUpload
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker_upload WHERE artifact_id = ?');
        $stmt->execute([$artifactId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? WorkerUpload::fromRow($row) : null;
    }

    public function zaehleFuerWorker(int $workerId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM worker_upload WHERE worker_id = ?');
        $stmt->execute([$workerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<WorkerUpload>
     */
    public function fuerJob(int $jobId): array
    {
        return $this->liste('job_id = ?', [$jobId]);
    }

    /**
     * @return list<WorkerUpload>
     */
    public function fuerWorker(int $workerId): array
    {
        return $this->liste('worker_id = ?', [$workerId]);
    }

    /**
     * @return list<WorkerUpload>
     */
    public function aelterAls(\DateTimeImmutable $vor, int $limit): array
    {
        return $this->liste(sprintf('created_at < ? ORDER BY artifact_id LIMIT %d', max(1, $limit)), [$vor->format(self::FORMAT)]);
    }

    public function loesche(int $artifactId): void
    {
        $this->pdo->prepare('DELETE FROM worker_upload WHERE artifact_id = ?')->execute([$artifactId]);
    }

    /**
     * @param list<int|string> $params
     *
     * @return list<WorkerUpload>
     */
    private function liste(string $bedingung, array $params): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker_upload WHERE ' . $bedingung);
        $stmt->execute($params);

        return array_map(WorkerUpload::fromRow(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
