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
     * Opens a fresh window for this key at count 1 - either the first attempt
     * ever, or the first one after the previous window ran out.
     */
    public function startWindow(string $keyHash, string $windowStart): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit (key_hash, window_start, `count`) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), `count` = 1',
        );
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $windowStart);
        $stmt->execute();
    }

    /**
     * Counts one more attempt inside the window that is already open. The
     * increment happens in the database, so two parallel requests cannot
     * read the same value and write it back twice.
     */
    public function increment(string $keyHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE rate_limit SET `count` = `count` + 1 WHERE key_hash = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
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
