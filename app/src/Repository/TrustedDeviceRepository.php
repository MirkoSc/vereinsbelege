<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\TrustedDevice;

/**
 * The `trusted_device` table (docs/spec/01-sicherheit.md section 3:
 * "'Dieses Gerät 30 Tage merken' (optional, Token gehasht, pro Nutzer
 * widerrufbar)"). `token_hash` is globally unique (migrations/008_mfa.sql),
 * so a lookup needs no user id - the caller still passes one, as a second
 * check that a hash collision could never quietly hand somebody else's
 * device credit for a login.
 */
final readonly class TrustedDeviceRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        int $userId,
        string $tokenHash,
        string $label,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $now = null,
    ): int {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO trusted_device (user_id, token_hash, label, created_at, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, NULL, ?)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $tokenHash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $label);
        $stmt->bindValue(4, $zeit);
        $stmt->bindValue(5, $expiresAt->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function findByTokenHash(int $userId, string $tokenHash): ?TrustedDevice
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, token_hash, label, created_at, last_used_at, expires_at
             FROM trusted_device WHERE user_id = ? AND token_hash = ?',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $tokenHash, \PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function touchLastUsed(int $id, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE trusted_device SET last_used_at = ? WHERE id = ?');
        $stmt->execute([($now ?? new \DateTimeImmutable())->format(self::FORMAT), $id]);
    }

    /**
     * For the security page's device list - newest first.
     *
     * @return list<TrustedDevice>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, token_hash, label, created_at, last_used_at, expires_at
             FROM trusted_device WHERE user_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$userId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** Revoke - the id is scoped to this user by the caller (own device list only). */
    public function delete(int $id, int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM trusted_device WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    }

    /**
     * Housekeeping for the cron (App\Service\Cron\TrustedDeviceCleanupTask):
     * an expired token cannot let anybody in anymore, so keeping the row
     * around would only be a hashed token kept for nothing
     * (docs/spec/01-sicherheit.md section 5's reasoning for the rate-limit
     * table applies here too).
     */
    public function deleteExpired(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM trusted_device WHERE expires_at < ?');
        $stmt->execute([$before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): TrustedDevice
    {
        return new TrustedDevice(
            id: (int) $row['id'],
            tokenHash: (string) $row['token_hash'],
            label: (string) $row['label'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            lastUsedAt: is_string($row['last_used_at']) ? new \DateTimeImmutable((string) $row['last_used_at']) : null,
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
        );
    }
}
