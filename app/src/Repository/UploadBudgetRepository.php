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
     * Holds $bytes of $keyHash's open-upload counter for $uploadId - true
     * when they fit under $limit, false when nothing changed. Counter and
     * reservation row are written together or not at all.
     */
    public function offenReservieren(string $uploadId, string $keyHash, int $bytes, int $limit, \DateTimeImmutable $now): bool
    {
        return $this->inTransaktion(function () use ($uploadId, $keyHash, $bytes, $limit, $now): bool {
            $anlegen = $this->pdo->prepare('INSERT IGNORE INTO upload_offen (key_hash, bytes) VALUES (?, 0)');
            $anlegen->bindValue(1, $keyHash, \PDO::PARAM_LOB);
            $anlegen->execute();

            $zaehlen = $this->pdo->prepare('UPDATE upload_offen SET bytes = bytes + ? WHERE key_hash = ? AND bytes + ? <= ?');
            $zaehlen->bindValue(1, $bytes, \PDO::PARAM_INT);
            $zaehlen->bindValue(2, $keyHash, \PDO::PARAM_LOB);
            $zaehlen->bindValue(3, $bytes, \PDO::PARAM_INT);
            $zaehlen->bindValue(4, $limit, \PDO::PARAM_INT);
            $zaehlen->execute();
            if ($zaehlen->rowCount() !== 1) {
                return false;
            }

            $merken = $this->pdo->prepare('INSERT INTO upload_reservierung (upload_id, key_hash, bytes, created_at) VALUES (?, ?, ?, ?)');
            $merken->bindValue(1, $uploadId);
            $merken->bindValue(2, $keyHash, \PDO::PARAM_LOB);
            $merken->bindValue(3, $bytes, \PDO::PARAM_INT);
            $merken->bindValue(4, self::utc($now));
            $merken->execute();

            return true;
        });
    }

    /**
     * Gives an upload's reservation back. Idempotent: the row is the
     * receipt, so finish, abort and the cron can run into each other.
     */
    public function offenFreigeben(string $uploadId): void
    {
        $this->inTransaktion(function () use ($uploadId): bool {
            $lesen = $this->pdo->prepare('SELECT key_hash, bytes FROM upload_reservierung WHERE upload_id = ? FOR UPDATE');
            $lesen->execute([$uploadId]);
            $zeile = $lesen->fetch();
            if (!is_array($zeile)) {
                return false;
            }

            $this->pdo->prepare('DELETE FROM upload_reservierung WHERE upload_id = ?')->execute([$uploadId]);
            $bytes = (int) $zeile['bytes'];
            $zurueck = $this->pdo->prepare('UPDATE upload_offen SET bytes = IF(bytes > ?, bytes - ?, 0) WHERE key_hash = ?');
            $zurueck->bindValue(1, $bytes, \PDO::PARAM_INT);
            $zurueck->bindValue(2, $bytes, \PDO::PARAM_INT);
            $zurueck->bindValue(3, (string) $zeile['key_hash'], \PDO::PARAM_LOB);
            $zurueck->execute();

            return true;
        });
    }

    /**
     * Upload ids whose reservation is older than $vor - uploads the cron
     * gives up on.
     *
     * @return list<string>
     */
    public function abgelaufeneReservierungen(\DateTimeImmutable $vor): array
    {
        $stmt = $this->pdo->prepare('SELECT upload_id FROM upload_reservierung WHERE created_at < ?');
        $stmt->execute([self::utc($vor)]);

        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Bytes $keyHash holds in open uploads right now. */
    public function offen(string $keyHash): int
    {
        $stmt = $this->pdo->prepare('SELECT bytes FROM upload_offen WHERE key_hash = ?');
        $stmt->bindValue(1, $keyHash, \PDO::PARAM_LOB);
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
        $entfernt = $stmt->rowCount();

        // Counters back at zero hold no information - only a hashed
        // address kept for nothing.
        $leer = $this->pdo->prepare('DELETE FROM upload_offen WHERE bytes = 0');
        $leer->execute();

        return $entfernt + $leer->rowCount();
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function inTransaktion(\Closure $arbeit): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $arbeit();
        }

        $this->pdo->beginTransaction();
        try {
            $ergebnis = $arbeit();
            $this->pdo->commit();

            return $ergebnis;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    private static function utc(\DateTimeImmutable $zeit): string
    {
        return $zeit->setTimezone(new \DateTimeZone(self::ZONE))->format(self::FORMAT);
    }
}
