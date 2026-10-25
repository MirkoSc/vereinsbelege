<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `upload_budget` table (migration 046, issue #242): bytes reserved per
 * key and window for the public upload (App\Service\Submission\UploadBudget).
 *
 * The check and the reservation are one conditional UPDATE, so two parallel
 * requests can never both slip under the limit with a value read before the
 * other one wrote (unlike a separate read and write).
 *
 * Nothing here is business data and nothing is encrypted - the key is a
 * digest when it arrives.
 */
final readonly class UploadBudgetRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * Stored in UTC: in local time the hour before and after the autumn
     * clock change would both read 02:00, and a window would end where it
     * starts.
     */
    private const string ZONE = 'UTC';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Reserves $bytes against $limit in the window [$start, $end) - true
     * when they fit and are now counted, false when nothing changed.
     *
     * A row of an earlier window is reset first; one of a later window (a
     * request that started just before a parallel one rolled the window
     * over) is left alone and the reservation counts there.
     */
    public function reservieren(
        string $keyHash,
        \DateTimeImmutable $start,
        \DateTimeImmutable $ende,
        int $bytes,
        int $limit,
    ): bool {
        $oeffnen = $this->pdo->prepare(
            'INSERT INTO upload_budget (key_hash, window_start, window_end, bytes) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                 bytes = IF(VALUES(window_start) > window_start, 0, bytes),
                 window_end = IF(VALUES(window_start) > window_start, VALUES(window_end), window_end),
                 window_start = GREATEST(window_start, VALUES(window_start))',
        );
        $oeffnen->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $oeffnen->bindValue(2, self::utc($start));
        $oeffnen->bindValue(3, self::utc($ende));
        $oeffnen->execute();

        $zaehlen = $this->pdo->prepare(
            'UPDATE upload_budget SET bytes = bytes + ? WHERE key_hash = ? AND bytes + ? <= ?',
        );
        $zaehlen->bindValue(1, $bytes, \PDO::PARAM_INT);
        $zaehlen->bindValue(2, $keyHash, \PDO::PARAM_LOB);
        $zaehlen->bindValue(3, $bytes, \PDO::PARAM_INT);
        $zaehlen->bindValue(4, $limit, \PDO::PARAM_INT);
        $zaehlen->execute();

        return $zaehlen->rowCount() === 1;
    }

    /**
     * Gives back a reservation - when the second of two budgets refused
     * after the first had already counted.
     */
    public function freigeben(string $keyHash, int $bytes): void
    {
        $stmt = $this->pdo->prepare('UPDATE upload_budget SET bytes = IF(bytes > ?, bytes - ?, 0) WHERE key_hash = ?');
        $stmt->bindValue(1, $bytes, \PDO::PARAM_INT);
        $stmt->bindValue(2, $bytes, \PDO::PARAM_INT);
        $stmt->bindValue(3, $keyHash, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * Bytes counted in the window that starts at $start; 0 without a row
     * or when the row belongs to another window.
     */
    public function verbraucht(string $keyHash, \DateTimeImmutable $start): int
    {
        $stmt = $this->pdo->prepare('SELECT bytes FROM upload_budget WHERE key_hash = ? AND window_start = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, self::utc($start));
        $stmt->execute();
        $wert = $stmt->fetchColumn();

        return $wert === false ? 0 : (int) $wert;
    }

    /**
     * @return int how many rows the sweep removed
     */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM upload_budget WHERE window_end <= ?');
        $stmt->execute([self::utc($now)]);

        return $stmt->rowCount();
    }

    private static function utc(\DateTimeImmutable $zeit): string
    {
        return $zeit->setTimezone(new \DateTimeZone(self::ZONE))->format(self::FORMAT);
    }
}
