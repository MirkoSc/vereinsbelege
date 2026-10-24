<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The table `worker_nonce`: replay protection for signed worker requests
 * (docs/spec/07-worker.md section 4, issue #51/M7b-1). The primary key
 * (worker_id, nonce) makes "seen before" a single INSERT - two requests with
 * the same nonce racing each other cannot both win.
 */
final readonly class WorkerNonceRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** MySQL/MariaDB: duplicate entry for a UNIQUE key. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private \PDO $pdo)
    {
    }

    /** False when this worker used the nonce before - a replay. */
    public function merken(int $workerId, string $nonce, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('INSERT INTO worker_nonce (worker_id, nonce, seen_at) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$workerId, $nonce, $now->format(self::FORMAT)]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_KEY) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM worker_nonce WHERE seen_at < ?');
        $stmt->execute([$before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
