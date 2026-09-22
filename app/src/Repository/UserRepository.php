<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\UserStatus;

/**
 * The `user` table (docs/spec/02-datenmodell.md "Benutzer und Sicherheit").
 * SQL lives in repositories only, prepared statements only.
 *
 * Operating data (email, display name) is encrypted with the SERVER key, not
 * the vault - the login lookup has to work before any session unlocks a
 * vault (CLAUDE.md section 4). Callers therefore hand in already-encrypted
 * values and the blind index; this class does not hold a crypto instance
 * itself, the same split App\Repository\MailQueueRepository does not follow
 * only because mail has just the one caller. The installer
 * (App\Installer\FirstAdminSetup) is the only writer until M3-7.
 */
final readonly class UserRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        string $emailEnc,
        string $emailBi,
        string $displayNameEnc,
        string $passwordHash,
        UserStatus $status = UserStatus::Aktiv,
        bool $mfaRequired = true,
        ?\DateTimeImmutable $now = null,
    ): int {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO `user` (email_enc, email_bi, display_name_enc, password_hash, status, mfa_required, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $emailEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $emailBi, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $displayNameEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $passwordHash);
        $stmt->bindValue(5, $status->value);
        $stmt->bindValue(6, $mfaRequired, \PDO::PARAM_BOOL);
        $stmt->bindValue(7, $zeit);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Whether any account already exists - the installer refuses a fresh
     * admin once one does (docs/spec/06-betrieb.md section 1).
     */
    public function count(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM `user`')->fetchColumn() ?: 0);
    }

    /**
     * Removes an unconfirmed installer account and, via the foreign keys in
     * migrations/006_user.sql, its `user_key` and `vault_grant` rows with it
     * (docs/spec/01-sicherheit.md section 2, "Neu beginnen").
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `user` WHERE id = ?');
        $stmt->execute([$id]);
    }
}
