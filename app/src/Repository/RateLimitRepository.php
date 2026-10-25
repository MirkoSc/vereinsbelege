<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `rate_limit` table (docs/spec/02-datenmodell.md "Betrieb"). SQL lives
 * in repositories only, prepared statements only - which is the one change
 * against the version taken over from MirkoSc/vereinskalender (CLAUDE.md
 * section 7/8), where the service held its own statements.
 *
 * One row per active key: the counter of a window, not a log of attempts.
 * Nothing here is business data, so nothing here is encrypted - the key is
 * already a digest when it arrives (App\Service\RateLimiter).
 */
final readonly class RateLimitRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{windowStart: string, count: int}|null
     */
    public function find(string $keyHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT window_start, `count` FROM rate_limit WHERE key_hash = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return ['windowStart' => (string) $row['window_start'], 'count' => (int) $row['count']];
    }

    /**
     * Counts one attempt and returns the row as it stands afterwards
     * (security review N-09, issue #246). The count and the window move in
     * ONE statement: a row whose window has run out starts over at 1, a
     * current one goes up by 1, a missing one is created - so parallel
     * requests never read the same value and write it back. MariaDB applies
     * the assignments left to right, which is why `count` (it compares the
     * OLD window) comes before `window_start`; GREATEST() keeps a request
     * with a slightly older clock from rolling a newer window back.
     *
     * The row read back afterwards can only be at or above this request's
     * own increment (or gone, after a success reset it) - never below it, so
     * a limit checked against it lets at most `limit` attempts through.
     *
     * @return array{windowStart: string, count: int}|null null only when a
     *         parallel reset removed the row in between
     */
    public function hit(string $keyHash, string $windowStart): ?array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit (key_hash, window_start, `count`) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
                 `count` = IF(window_start < VALUES(window_start), 1, `count` + 1),
                 window_start = GREATEST(window_start, VALUES(window_start))',
        );
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $windowStart);
        $stmt->execute();

        return $this->find($keyHash);
    }

    /**
     * Takes one count back - an attempt that turned out not to be a failure
     * (App\Service\RateLimiter::erstatten()). Only inside the same window
     * and never below zero.
     */
    public function decrement(string $keyHash, string $windowStart): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rate_limit SET `count` = `count` - 1 WHERE key_hash = ? AND window_start = ? AND `count` > 0',
        );
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $windowStart);
        $stmt->execute();
    }

    public function delete(string $keyHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE key_hash = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * @return int how many rows the sweep removed
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE window_start < ?');
        $stmt->execute([$cutoff->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
