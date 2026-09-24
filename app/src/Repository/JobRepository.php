<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Service\Job\JobSchrittErgebnis;

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
     *
     * @param list<string> $typen the job types a caller may claim (issue
     *        #29/M4-7's JobRunner: the types this account's rights allow).
     *        An empty list claims nothing at all - it means the caller has
     *        no right for any type, not "any type". Takes precedence over
     *        $typ, which stays for the single-type callers this predates
     *        (the cron's `JobCleanupTask` reads finished jobs directly, and
     *        the worker module (07-worker.md) will claim one type at a
     *        time).
     */
    public function claim(
        JobExecutor $executor,
        string $lockedBy,
        int $lockSeconds,
        ?string $typ = null,
        ?\DateTimeImmutable $now = null,
        ?array $typen = null,
    ): ?Job {
        if ($typen === []) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $jetzt = $now->format(self::FORMAT);
        $bis = $now->modify(sprintf('+%d seconds', $lockSeconds))->format(self::FORMAT);

        $sql = 'SELECT id FROM job
                WHERE status IN (?, ?) AND executor = ? AND (locked_until IS NULL OR locked_until <= ?)';
        $params = [JobStatus::Offen->value, JobStatus::Laeuft->value, $executor->value, $jetzt];
        if ($typen !== null) {
            $sql .= sprintf(' AND typ IN (%s)', implode(',', array_fill(0, count($typen), '?')));
            array_push($params, ...$typen);
        } elseif ($typ !== null) {
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
     * Writes one JobHandler::schritt() result (issue #29/M4-7's JobRunner)
     * and releases the lock either way - the next step, whoever runs it, is
     * free to claim the job again. Only the holder of the lock may: once it
     * expired and someone else claimed the job, the old holder's result must
     * not overwrite what the new one is doing.
     *
     * Resets `attempts` to 0: this step made progress, so JobRunner's guard
     * against a job that crashes every request it touches should not count
     * this one.
     */
    public function schrittErledigt(int $id, string $lockedBy, JobSchrittErgebnis $ergebnis, ?\DateTimeImmutable $now = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job
             SET status = ?, step = ?, state = ?, attempts = 0, locked_by = NULL, locked_until = NULL, updated_at = ?
             WHERE id = ? AND status = ? AND locked_by = ?',
        );
        $stmt->execute([
            $ergebnis->status->value,
            $ergebnis->step,
            json_encode($ergebnis->state, JSON_THROW_ON_ERROR),
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
            JobStatus::Laeuft->value,
            $lockedBy,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Marks a job failed. Takes the exception CLASS, not its message: a
     * database message quotes row data, and this column is plaintext.
     *
     * @param ?string $lockedBy set by a caller that holds a claim (issue
     *        #29/M4-7's JobRunner): the failure is only written while it
     *        still holds the lock, the same rule schrittErledigt() follows.
     *        Null keeps the previous, unconditional behaviour for callers
     *        without a claim of their own.
     */
    public function fail(int $id, string $errorClass, ?\DateTimeImmutable $now = null, ?string $lockedBy = null): void
    {
        $sql = 'UPDATE job
                SET status = ?, last_error = ?, locked_by = NULL, locked_until = NULL, updated_at = ?
                WHERE id = ?';
        $params = [
            JobStatus::Fehler->value,
            mb_substr($errorClass, 0, 255),
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
        ];
        if ($lockedBy !== null) {
            $sql .= ' AND status = ? AND locked_by = ?';
            $params[] = JobStatus::Laeuft->value;
            $params[] = $lockedBy;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * How many jobs of these types still wait or run - the header count
     * (issue #29/M4-7, docs/spec/06-betrieb.md section 4). Plaintext only,
     * needs no vault.
     *
     * @param list<string> $typen empty means "no right for any type", so it
     *        counts nothing rather than everything.
     */
    public function zaehleOffen(JobExecutor $executor, array $typen): int
    {
        if ($typen === []) {
            return 0;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM job WHERE executor = ? AND status IN (?, ?) AND typ IN (%s)',
            implode(',', array_fill(0, count($typen), '?')),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$executor->value, JobStatus::Offen->value, JobStatus::Laeuft->value, ...$typen]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Gives a claimed job back without finishing it (tab closed, step
     * postponed, browser gave up on it - App\Api\RasterungController).
     *
     * @param ?string $lockedBy set by a caller that holds a claim, same
     *        reasoning as fail(): only the current holder may give the job
     *        back, so a request that lost a race for the lock cannot undo
     *        what the winner is doing. Null keeps the previous,
     *        unconditional behaviour for callers without a claim of their own.
     */
    public function release(int $id, ?\DateTimeImmutable $now = null, ?string $lockedBy = null): void
    {
        $sql = 'UPDATE job
                SET status = ?, locked_by = NULL, locked_until = NULL, updated_at = ?
                WHERE id = ? AND status = ?';
        $params = [
            JobStatus::Offen->value,
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            $id,
            JobStatus::Laeuft->value,
        ];
        if ($lockedBy !== null) {
            $sql .= ' AND locked_by = ?';
            $params[] = $lockedBy;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Writes progress on a job that stays claimed across many small
     * requests instead of one call per step (issue #30/M4-8's browser job:
     * the browser fetches one task and then uploads many pages against the
     * same lock, unlike the session job's one-call-per-step in
     * schrittErledigt()). Only the holder may - like heartbeat(), but also
     * carries the new state and resets `attempts`, because a page that made
     * it into the database is progress, the same reasoning schrittErledigt()
     * follows.
     *
     * @param array<string, mixed> $state ids and counters only, never business data
     */
    public function fortschritt(int $id, string $lockedBy, array $state, int $lockSeconds, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'UPDATE job
             SET state = ?, attempts = 0, locked_until = ?, updated_at = ?
             WHERE id = ? AND status = ? AND locked_by = ?',
        );
        $stmt->execute([
            json_encode($state, JSON_THROW_ON_ERROR),
            $now->modify(sprintf('+%d seconds', $lockSeconds))->format(self::FORMAT),
            $now->format(self::FORMAT),
            $id,
            JobStatus::Laeuft->value,
            $lockedBy,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Whether a job of this type already exists for this row (issue
     * #30/M4-8: App\Service\Document\PdfErzeugung enqueues `render_pages` at
     * most once per document). Any status counts - a finished or failed job
     * still means one was created; nothing here re-triggers a failed run.
     */
    public function gibtEs(string $typ, string $refType, int $refId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM job WHERE typ = ? AND ref_type = ? AND ref_id = ?');
        $stmt->execute([$typ, $refType, $refId]);

        return $stmt->fetch() !== false;
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
