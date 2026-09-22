<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MfaTotpSecret;

/**
 * The `mfa_totp` table (docs/spec/02-datenmodell.md, migrations/008_mfa.sql).
 * SQL lives in repositories only, prepared statements only. Callers hand in
 * already-encrypted secrets (App\Service\Crypto\ServerCrypto) - this class
 * holds no key, the same split App\Repository\UserRepository follows.
 */
final readonly class MfaTotpRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Overwrites any previous secret: starting TOTP setup again before
     * confirming replaces the unconfirmed attempt rather than accumulating
     * rows (App\Service\Account\MfaEnrollment).
     */
    public function put(int $userId, string $secretEnc, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mfa_totp (user_id, secret_enc, confirmed_at, created_at) VALUES (?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), confirmed_at = NULL, created_at = VALUES(created_at)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $secretEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(3, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();
    }

    public function forUser(int $userId): ?MfaTotpSecret
    {
        $stmt = $this->pdo->prepare('SELECT secret_enc, confirmed_at FROM mfa_totp WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new MfaTotpSecret(
            secretEnc: (string) $row['secret_enc'],
            confirmedAt: is_string($row['confirmed_at']) ? new \DateTimeImmutable($row['confirmed_at']) : null,
        );
    }

    public function confirm(int $userId, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_totp SET confirmed_at = ? WHERE user_id = ?');
        $stmt->execute([($now ?? new \DateTimeImmutable())->format(self::FORMAT), $userId]);
    }

    public function delete(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_totp WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
