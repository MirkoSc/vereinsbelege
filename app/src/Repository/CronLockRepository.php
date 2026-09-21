<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The run lock of the cron endpoint (issue #99).
 *
 * The host allows a cron job every minute, so a run that takes longer than
 * that would overlap with the next one. The lock is one row in `setting`
 * holding the moment it expires - same semantics as `job.locked_until`: it
 * lapses on its own, so a run that died mid-way blocks the cron for at most
 * the time to live, not forever.
 *
 * Acquiring is ONE statement, not read-then-write: two requests arriving in
 * the same second must not both see a free lock.
 */
final readonly class CronLockRepository
{
    public const string LOCK_KEY = 'cron_lock_until';

    private const string FORMAT = 'Y-m-d H:i:s';

    /** "Free" in a column that has to hold a timestamp and compares as a string. */
    private const string FREE = '1970-01-01 00:00:00';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Takes the lock if it is free or expired.
     *
     * @return ?string a token for release(), or null when another run holds it
     */
    public function acquire(int $ttlSeconds, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();
        $jetzt = $now->format(self::FORMAT);
        $bis = $now->modify(sprintf('+%d seconds', $ttlSeconds))->format(self::FORMAT);

        // Timestamps compare correctly as strings (fixed width, big-endian).
        // updated_at is assigned first: the assignments run left to right, and
        // the condition must still see the OLD value.
        $stmt = $this->pdo->prepare(
            'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                updated_at = IF(value <= ?, VALUES(updated_at), updated_at),
                value = IF(value <= ?, VALUES(value), value)',
        );
        $stmt->execute([self::LOCK_KEY, $bis, $jetzt, $jetzt, $jetzt]);

        // 1 = inserted, 2 = existing row changed, 0 = still held by someone.
        return $stmt->rowCount() > 0 ? $bis : null;
    }

    /**
     * Frees the lock - but only if it is still OURS: after our time to live
     * ran out, another run may hold it by now, and that one must stay locked.
     */
    public function release(string $token, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE setting SET value = ?, updated_at = ? WHERE name = ? AND value = ?',
        );
        $stmt->execute([
            self::FREE,
            ($now ?? new \DateTimeImmutable())->format(self::FORMAT),
            self::LOCK_KEY,
            $token,
        ]);
    }
}
