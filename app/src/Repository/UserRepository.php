<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use App\Domain\UserStatus;
use App\Service\Account\MfaMethod;

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
 *
 * Reading follows the same rule: the rows come back with `email_enc` and
 * `display_name_enc` untouched (App\Domain\User), so this class needs no
 * key at all and nothing decrypts an address that nobody asked for.
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
     * The login lookup (docs/spec/01-sicherheit.md section 3): the only
     * index that works before any session exists, because `email_bi` is
     * keyed from the SERVER key and not from the vault
     * (App\Service\Crypto\ServerCrypto::blindIndex()).
     */
    public function findByEmailBlindIndex(string $emailBi): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `user` WHERE email_bi = ?');
        $stmt->bindValue(1, $emailBi, \PDO::PARAM_LOB);
        $stmt->execute();

        return self::hydrate($stmt->fetch());
    }

    /**
     * The logged-in user of a request: the session carries the id, never the
     * name or the address (App\Http\Session).
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `user` WHERE id = ?');
        $stmt->execute([$id]);

        return self::hydrate($stmt->fetch());
    }

    /**
     * Records a successful login (docs/spec/01-sicherheit.md section 3).
     * Time from PHP, not NOW(): the database session may run in UTC while
     * the application convention is Europe/Berlin (bootstrap.php).
     */
    public function touchLastLogin(int $id, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET last_login_at = ? WHERE id = ?');
        $stmt->execute([($now ?? new \DateTimeImmutable())->format(self::FORMAT), $id]);
    }

    /**
     * Stores a freshly computed password hash. Used when the cost factors of
     * password_hash() have been raised since the row was written and the
     * login just had the plaintext in hand anyway
     * (App\Service\Account\PasswordHasher::needsRehash()).
     *
     * This does NOT touch `user_key`: the KEK salt is deliberately separate
     * from the password hash (docs/spec/01-sicherheit.md section 3), and the
     * wrapping keeps its own parameters until the password itself changes
     * (App\Service\Crypto\UserKey::rewrap(), M3-5).
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    /**
     * Ends the account's sessions (issue #18/M3-5): every session carries
     * the value from its login, and App\Http\LoginGuard turns away the
     * ones that no longer match. Returns the new value so that the one
     * session that changed the password can adopt it and stay.
     */
    public function bumpSessionEpoch(int $id): int
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET session_epoch = session_epoch + 1 WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('SELECT session_epoch FROM `user` WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Records which second factor an account has committed to
     * (docs/spec/01-sicherheit.md section 3, issue #17). `null` clears it -
     * used when a method is abandoned mid-setup or replaced by another one
     * (App\Service\Account\MfaEnrollment).
     */
    public function updateMfaMethod(int $id, ?MfaMethod $method): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET mfa_method = ? WHERE id = ?');
        $stmt->execute([$method?->value, $id]);
    }

    /**
     * The end date of an external account (docs/spec/01-sicherheit.md
     * section 4); `null` for a regular one. App\Domain\User::mayLogIn()
     * refuses the account from that moment on, and App\Http\LoginGuard ends
     * a session that outlives it (issue #19/M3-6).
     */
    public function updateExpiry(int $id, ?\DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET expires_at = ? WHERE id = ?');
        $stmt->execute([$expiresAt?->format(self::FORMAT), $id]);
    }

    /** External accounts always need a second factor (01 section 4). */
    /**
     * Invite, activate, lock, unlock (issue #20/M3-7). The rules around it -
     * who may be locked, what a lock does to the vault grant - live in
     * App\Service\Account\UserAdministration; this only writes the column.
     */
    public function updateStatus(int $id, UserStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    /** $displayNameEnc is server-key ciphertext, like at insert(). */
    public function updateDisplayName(int $id, string $displayNameEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET display_name_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $displayNameEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Every account, oldest first - for the user management (M3-7). The
     * order cannot be by name: names are encrypted, the caller sorts after
     * decrypting (a club has dozens of accounts, not thousands).
     *
     * @return list<User>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM `user` ORDER BY id')->fetchAll();

        return array_values(array_filter(array_map(self::hydrate(...), $rows)));
    }

    public function updateMfaRequired(int $id, bool $required): void
    {
        $stmt = $this->pdo->prepare('UPDATE `user` SET mfa_required = ? WHERE id = ?');
        $stmt->bindValue(1, $required, \PDO::PARAM_BOOL);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
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

    /**
     * An unknown status value is not silently turned into `aktiv`: a row a
     * newer release wrote must not accidentally let somebody in here. It
     * becomes `gesperrt`, which App\Domain\User::mayLogIn() refuses.
     */
    private static function hydrate(mixed $row): ?User
    {
        if (!is_array($row)) {
            return null;
        }

        return new User(
            id: (int) $row['id'],
            emailEnc: (string) $row['email_enc'],
            displayNameEnc: (string) $row['display_name_enc'],
            passwordHash: (string) $row['password_hash'],
            status: UserStatus::tryFrom((string) $row['status']) ?? UserStatus::Gesperrt,
            expiresAt: self::time($row['expires_at']),
            mfaRequired: (bool) $row['mfa_required'],
            mfaMethod: is_string($row['mfa_method'] ?? null) ? MfaMethod::tryFrom($row['mfa_method']) : null,
            createdAt: self::time($row['created_at']) ?? new \DateTimeImmutable(),
            lastLoginAt: self::time($row['last_login_at']),
            sessionEpoch: (int) ($row['session_epoch'] ?? 0),
        );
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
