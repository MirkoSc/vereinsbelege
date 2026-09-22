<?php

declare(strict_types=1);

namespace App\Installer;

use App\Domain\SystemRole;
use App\Repository\RoleRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\PasswordHasher;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\UserKey;
use App\Service\Crypto\UserKeyPair;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;

/**
 * Steps 3 and 4 of the installer flow (docs/spec/06-betrieb.md section 1,
 * docs/spec/01-sicherheit.md section 2): creates the vault, the first admin
 * and the grant that ties them together. Pulled out of InstallController so
 * the crypto/DB orchestration is testable on its own; the crypto primitives
 * themselves (Vault, UserKeyPair, UserKey, VaultGrant, RecoveryKey) come
 * unchanged from M2-2, and the password hash from the same
 * App\Service\Account\PasswordHasher the login uses (M3-3) - an account
 * created here has to verify there.
 *
 * The recovery key is generated here and handed back once - it is never
 * stored (docs/spec/01-sicherheit.md, "Wiederherstellungsschlüssel"). Only
 * the server key travels back into the session (as a string, the same way
 * the restore chain already carries one), because InstallController needs it
 * one request later to write config.php once the key is confirmed.
 */
final readonly class FirstAdminSetup
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Whether a vault generation already exists - a fresh install refuses to
     * run twice (docs/spec/06-betrieb.md section 1).
     */
    public function vaultExists(): bool
    {
        return new VaultRepository($this->pdo)->current() !== null;
    }

    /**
     * Creates the vault, the admin account, their key pair and the grant, in
     * one transaction. The email is looked up in cleartext only here, to
     * seal it - App\Repository\UserRepository never sees it.
     *
     * @return array{userId: int, vaultVersion: int, recoveryKey: RecoveryKey}
     */
    public function create(
        string $email,
        string $displayName,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $serverKeyBase64,
    ): array {
        $serverKey = base64_decode($serverKeyBase64, true);
        if ($serverKey === false) {
            throw new \RuntimeException('Der Server-Schlüssel ist ungültig.');
        }
        $crypto = new ServerCrypto($serverKey);

        $vault = Vault::create();
        $userPair = UserKeyPair::create();
        $wrappedKey = UserKey::wrap($userPair, $password);
        $grant = VaultGrant::seal($vault, $userPair->publicKey());
        $passwordHash = new PasswordHasher()->hash($password);
        $recoveryKey = RecoveryKey::forVault($vault);

        $this->pdo->beginTransaction();
        try {
            new VaultRepository($this->pdo)->insert($vault->publicKey(), $vault->version);

            $userId = new UserRepository($this->pdo)->insert(
                $crypto->encrypt($email),
                $crypto->blindIndex()->forValue('user.email', $email),
                $crypto->encrypt($displayName),
                $passwordHash,
            );

            new UserKeyRepository($this->pdo)->insert($userId, $wrappedKey);
            // The installer grants to itself - there is no other admin yet
            // to attribute the grant to (migrations/006_user.sql: granted_by NULL).
            new VaultGrantRepository($this->pdo)->insert($userId, $grant, grantedBy: null);

            // The first account is the administrator (docs/spec/
            // 01-sicherheit.md section 4, issue #19/M3-6). The role row comes
            // from migrations/010_role.sql, which the installer ran before.
            $admin = new RoleRepository($this->pdo)->findSystem(SystemRole::Admin)
                ?? throw new \RuntimeException('Die Admin-Rolle fehlt – sind alle Migrationen gelaufen?');
            new RoleRepository($this->pdo)->assignToUser($userId, [$admin->id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['userId' => $userId, 'vaultVersion' => $vault->version, 'recoveryKey' => $recoveryKey];
    }

    /**
     * "Neu beginnen": the browser was closed before the recovery key was
     * confirmed. Deleting the user cascades to `user_key` and `vault_grant`
     * (migrations/006_user.sql), so only the vault row itself needs its own
     * delete - nothing references it anymore afterwards.
     */
    public function remove(int $userId, int $vaultVersion): void
    {
        $this->pdo->beginTransaction();
        try {
            new UserRepository($this->pdo)->delete($userId);
            new VaultRepository($this->pdo)->delete($vaultVersion);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

}
