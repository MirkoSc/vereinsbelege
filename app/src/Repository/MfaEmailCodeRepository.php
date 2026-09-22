<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MfaEmailCode;

/**
 * The `mfa_email_code` table (docs/spec/01-sicherheit.md section 3: "6
 * Ziffern, 10 min gültig, max. 5 Versuche, nur Hash gespeichert"). One row
 * per user - a fresh request overwrites it rather than accumulating one row
 * per code ever sent.
 */
final readonly class MfaEmailCodeRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function put(int $userId, string $codeHash, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mfa_email_code (user_id, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
                attempts = 0, created_at = VALUES(created_at)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $codeHash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $expiresAt->format(self::FORMAT));
        $stmt->bindValue(4, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();
    }

    public function forUser(int $userId): ?MfaEmailCode
    {
        $stmt = $this->pdo->prepare('SELECT code_hash, expires_at, attempts FROM mfa_email_code WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new MfaEmailCode(
            codeHash: (string) $row['code_hash'],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            attempts: (int) $row['attempts'],
        );
    }

    public function registerAttempt(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_email_code SET attempts = attempts + 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /** A verified code, or one replaced by a fresh request, is removed outright. */
    public function delete(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_email_code WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
