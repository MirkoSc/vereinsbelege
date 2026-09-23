<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `auth_token` table (docs/spec/02-datenmodell.md, migrations/
 * 009_password_reset.sql): one-time links sent by mail. Only the digest of a
 * token is ever stored or looked up; the token itself exists in the mail and
 * in the browser that follows the link (App\Service\Account\PasswordReset).
 */
final readonly class AuthTokenRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        int $userId,
        string $typ,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $now = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_token (user_id, typ, token_hash, expires_at, used_at, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $typ);
        $stmt->bindValue(3, $tokenHash, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $expiresAt->format(self::FORMAT));
        $stmt->bindValue(5, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The user a still usable token belongs to: right type, not used, not
     * expired. Every other case is the same null - the caller has nothing to
     * gain from knowing which of them it was, and neither does the browser.
     *
     * @return array{id: int, user_id: int}|null
     */
    public function findUsable(string $typ, string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM auth_token
             WHERE typ = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?',
        );
        $stmt->bindValue(1, $typ);
        $stmt->bindValue(2, $tokenHash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']] : null;
    }

    /**
     * Spends a token. The `used_at IS NULL` condition makes this the actual
     * one-time check: of two requests racing with the same link, only one
     * gets `true` back.
     */
    public function markUsed(int $id, ?\DateTimeImmutable $now = null): bool
    {
        $stmt = $this->pdo->prepare('UPDATE auth_token SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $stmt->execute([($now ?? new \DateTimeImmutable())->format(self::FORMAT), $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Drops a user's tokens of one type - a newly requested reset link
     * replaces the previous one rather than adding a second valid door.
     */
    public function deleteForUser(int $userId, string $typ): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_token WHERE user_id = ? AND typ = ?');
        $stmt->execute([$userId, $typ]);
    }

    /**
     * For the cron: rows whose link has run out are kept for nothing.
     */
    public function deleteExpired(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_token WHERE expires_at < ?');
        $stmt->execute([$before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }

    /**
     * Whether $userId still has an unused, unexpired link of type $typ -
     * the user list shows an invitation nobody answered in time
     * (issue #20/M3-7).
     */
    public function hasUsable(int $userId, string $typ, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM auth_token WHERE user_id = ? AND typ = ? AND used_at IS NULL AND expires_at > ? LIMIT 1',
        );
        $stmt->execute([$userId, $typ, $now->format(self::FORMAT)]);

        return $stmt->fetchColumn() !== false;
    }
}
