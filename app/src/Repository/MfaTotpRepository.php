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
     * Replaces the account's secret with one whose first code was just
     * confirmed - confirmed and with that code's step spent, in ONE
     * statement (issue #246, N-08). Until this runs, the previous secret
     * stays exactly as it was: a setup that is started and abandoned never
     * touches it (App\Service\Account\MfaEnrollment).
     */
    public function putConfirmed(int $userId, string $secretEnc, int $step, ?\DateTimeImmutable $now = null): void
    {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mfa_totp (user_id, secret_enc, confirmed_at, last_step, created_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), confirmed_at = VALUES(confirmed_at),
                 last_step = VALUES(last_step), created_at = VALUES(created_at)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $secretEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $zeit);
        $stmt->bindValue(4, $step, \PDO::PARAM_INT);
        $stmt->bindValue(5, $zeit);
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

    /**
     * Records `$step` as the last accepted TOTP time step - true only if it
     * is newer than the one recorded so far, in a single statement, so of
     * two requests with the same code exactly one wins (issue #78,
     * migrations/045_totp_last_step.sql).
     */
    public function claimStep(int $userId, int $step): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mfa_totp SET last_step = ? WHERE user_id = ? AND confirmed_at IS NOT NULL AND (last_step IS NULL OR last_step < ?)',
        );
        $stmt->bindValue(1, $step, \PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $step, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function delete(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mfa_totp WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
