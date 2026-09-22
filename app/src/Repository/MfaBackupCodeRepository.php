<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MfaBackupCode;

/**
 * The `mfa_backup_code` table (docs/spec/01-sicherheit.md section 3: "10
 * Einmal-Backup-Codes (gehasht) bei Einrichtung"). `code_hash` is a
 * deterministic HMAC (App\Service\Crypto\BlindIndex), the same primitive
 * `user.email_bi` uses - a submitted code is looked up by its hash directly
 * rather than compared one by one.
 */
final readonly class MfaBackupCodeRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Replaces the whole set: setup and "Codes neu erzeugen" both mean the
     * previous ten (spent or not) stop working the moment a new ten exist.
     *
     * @param list<string> $codeHashes
     */
    public function replaceAll(int $userId, array $codeHashes, ?\DateTimeImmutable $now = null): void
    {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM mfa_backup_code WHERE user_id = ?');
            $delete->execute([$userId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO mfa_backup_code (user_id, code_hash, used_at, created_at) VALUES (?, ?, NULL, ?)',
            );
            foreach ($codeHashes as $codeHash) {
                $insert->bindValue(1, $userId, \PDO::PARAM_INT);
                $insert->bindValue(2, $codeHash, \PDO::PARAM_LOB);
                $insert->bindValue(3, $zeit);
                $insert->execute();
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findUnused(int $userId, string $codeHash): ?MfaBackupCode
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code_hash, used_at FROM mfa_backup_code WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $codeHash, \PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function markUsed(int $id, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_backup_code SET used_at = ? WHERE id = ?');
        $stmt->execute([($now ?? new \DateTimeImmutable())->format(self::FORMAT), $id]);
    }

    /** For the security page: "9 von 10 Codes noch gültig". */
    public function countUnused(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mfa_backup_code WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function deleteAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_backup_code WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): MfaBackupCode
    {
        return new MfaBackupCode(
            id: (int) $row['id'],
            codeHash: (string) $row['code_hash'],
            usedAt: is_string($row['used_at']) ? new \DateTimeImmutable($row['used_at']) : null,
        );
    }
}
