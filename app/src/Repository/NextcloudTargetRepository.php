<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The table `nextcloud_target` (migrations/032_nextcloud.sql, issue #95):
 * the club's one Nextcloud folder, as vault ciphertext. SQL only - the
 * encryption is App\Service\Export\Nextcloud\NextcloudZielSpeicher.
 */
final readonly class NextcloudTargetRepository
{
    /** The only row. */
    public const int ID = 1;

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{dek_sealed: string, data_enc: string, updated_at: \DateTimeImmutable}|null
     */
    public function find(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT dek_sealed, data_enc, updated_at FROM nextcloud_target WHERE id = ?');
        $stmt->execute([self::ID]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'dek_sealed' => (string) $row['dek_sealed'],
            'data_enc' => (string) $row['data_enc'],
            'updated_at' => new \DateTimeImmutable((string) $row['updated_at']),
        ];
    }

    public function speichern(string $dekSealed, string $dataEnc, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nextcloud_target (id, dek_sealed, data_enc, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE dek_sealed = VALUES(dek_sealed), data_enc = VALUES(data_enc),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
        );
        $stmt->bindValue(1, self::ID, \PDO::PARAM_INT);
        $stmt->bindValue(2, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /** @return bool whether there was a row */
    public function loeschen(): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM nextcloud_target WHERE id = ?');
        $stmt->execute([self::ID]);

        return $stmt->rowCount() === 1;
    }
}
