<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;

/**
 * The `job` table (docs/spec/06-betrieb.md section 4). SQL lives in
 * repositories only, prepared statements only.
 *
 * The lock is `locked_by` + `locked_until`: claim() hands a job to exactly one
 * caller until the lock runs out, so several open tabs, users or the worker
 * cannot process the same job twice. The claim is optimistic - a conditional
 * UPDATE whose row count says who won - and deliberately not SELECT ... FOR
 * UPDATE: no transaction stays open across a job step (issue #98).
 *
 * Nothing in here may store business data: `state` and `last_error` are
 * plaintext columns.
 */
final readonly class JobRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** How many candidates one claim() looks at before giving up. */
    private const int CANDIDATES = 10;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $state ids and counters only, never business data
     */
    public function enqueue(
        string $typ,
        JobExecutor $executor,
        string $refType = '',
        ?int $refId = null,
        array $state = [],
        string $step = '',
        ?\DateTimeImmutable $now = null,
    ): int {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO job (typ, ref_type, ref_id, executor, status, step, state, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $typ,
            $refType,
            $refId,
            $executor->value,
            JobStatus::Offen->value,
            $step,
            json_encode($state, JSON_THROW_ON_ERROR),
            $zeit,
            $zeit,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Job
    {
        $stmt = $this->pdo->prepare('SELECT * FROM job WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : Job::fromRow($row);
    }

    /**
     * Hands out the oldest runnable job of an executor and locks it, or
     * returns null. Runnable: `offen`, or `laeuft` with an expired lock (the
     * previous holder crashed or its tab closed).
     */
    public function claim(
        JobExecutor $executor,
        string $lockedBy,
        int $lockSeconds,
        ?string $typ = null,
        ?\DateTimeImmutable $now = null,
    ): ?Job {
        $now ??= new \DateTimeImmutable();
        $jetzt = $now->format(self::FORMAT);
        $bis = $now->modify(sprintf('+%d seconds', $lockSeconds))->format(self::FORMAT);

        $sql = 'SELECT id FROM job
                WHERE status IN (?, ?) AND executor = ? AND (locked_until IS NULL OR locked_until <= ?)';
        $params = [JobStatus::Offen->value, JobStatus::Laeuft->value, $executor->value, $jetzt];
        if ($typ !== null) {
            $sql .= ' AND typ = ?';
            $params[] = $typ;
        }
        $sql .= sprintf(' ORDER BY id LIMIT %d', self::CANDIDATES);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $update = $this->pdo->prepare(
            'UPDATE job
             SET status = ?, locked_by = ?, locked_until = ?, attempts = attempts + 1, updated_at = ?
             WHERE id = ? AND status IN (?, ?) AND (locked_until IS NULL OR locked_until <= ?)',
        );
        foreach ($ids as $id) {
            $update->execute([
                JobStatus::Laeuft->value,
                $lockedBy,
                $bis,
                $jetzt,
                $id,
                JobStatus::Offen->value,
                JobStatus::Laeuft->value,
                $jetzt,
            ]);
            // Another caller was faster for this row: try the next one.
            if ($update->rowCount() === 1) {
                return $this->find((int) $id);
            }
        }

        return null;
    }

    /**
     * Extends the lock of a long step. Only the holder can: after the lock
     * expired and someone else claimed the job this returns false, and the
     * old holder has to stop.
     */
    public function heartbeat(int $id, string $lockedBy, int $lockSeconds, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'UPDATE job SET locked_until = ?, updated_at = ?
             WHERE id = ? AND status = ? AND locked_by = ? AND locked_until > ?',
        );
        $stmt->execute([
            $now->modify(sprintf('+%d seconds', $lockSeconds))->format(self::FORMAT),
            $now->format(self::FORMAT),
            $id,
            JobStatus::Laeuft->value,
            $lockedBy,
            $now->format(self::FORMAT),
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param ?array<string, mixed> $state new state, or null to keep the stored one
     */
    public function finish(int $id, JobStatus $status, ?array $state = null, ?\DateTimeImmutable $now = null): void
    {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'UPDATE job
             SET status = ?, state = COALESCE(?, state), locked_by = NULL, locked_until = NULL, updated_at = ?
             WHERE id = ?',
        );
        $stmt->execute([
            $status->value,
            $state === null ? null : json_encode($state, JSON_THROW_ON_ERROR),
            $zeit,
            $id,
        ]);
    }

    /**
     * Marks a job failed. Takes the exception CLASS, not its message: a
     * database message quotes row data, and this column is plaintext.
     */
    public function fail(int $id, string $errorClass, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job
             SET status = ?, last_error = ?, locked_by = NULL, locked_until = NULL, updated_at = ?
             WHERE id = ?',
        );
        $stmt->execute([
            JobStatus::Fehler->value,
            mb_substr($errorClass, 0, 255),
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
        ]);
    }

    /** Gives a claimed job back without finishing it (tab closed, step postponed). */
    public function release(int $id, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job
             SET status = ?, locked_by = NULL, locked_until = NULL, updated_at = ?
             WHERE id = ? AND status = ?',
        );
        $stmt->execute([
            JobStatus::Offen->value,
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
            JobStatus::Laeuft->value,
        ]);
    }

    /**
     * Housekeeping for the cron: removes jobs that are done and will not run
     * again. `fehler` stays - someone should look at it.
     */
    public function deleteFinishedBefore(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM job WHERE status IN (?, ?) AND updated_at < ?');
        $stmt->execute([
            JobStatus::Fertig->value,
            JobStatus::Uebersprungen->value,
            $before->format(self::FORMAT),
        ]);

        return $stmt->rowCount();
    }
}
