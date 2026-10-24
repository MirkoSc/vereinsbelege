<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Crypto\KdfParameters;
use App\Service\Crypto\UserKey;

/**
 * The `user_key` table (docs/spec/02-datenmodell.md): one row per user,
 * written once when their key pair is created (installer for the first
 * admin, M3-2; invitation flow from M3-7). SQL lives in repositories only.
 */
final readonly class UserKeyRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(int $userId, UserKey $key, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_key (user_id, public_key, wrapped_private_key, kdf_salt, kdf_ops, kdf_mem, kdf_alg, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $key->publicKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(3, $key->wrappedPrivateKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(4, $key->kdf()->salt, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $key->kdf()->opsLimit, \PDO::PARAM_INT);
        $stmt->bindValue(6, $key->kdf()->memLimit, \PDO::PARAM_INT);
        $stmt->bindValue(7, $key->kdf()->algorithm, \PDO::PARAM_INT);
        $stmt->bindValue(8, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * Swaps the whole row for a new wrapping (issue #18/M3-5): after a
     * password change the same key pair under a new KEK
     * (App\Service\Crypto\UserKey::rewrap()), after a reset an entirely new
     * key pair - the public key is overwritten too, which is what makes any
     * grant sealed to the old one worthless.
     */
    public function replace(int $userId, UserKey $key): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_key SET public_key = ?, wrapped_private_key = ?, kdf_salt = ?, kdf_ops = ?, kdf_mem = ?, kdf_alg = ?
             WHERE user_id = ?',
        );
        $stmt->bindValue(1, $key->publicKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(2, $key->wrappedPrivateKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(3, $key->kdf()->salt, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $key->kdf()->opsLimit, \PDO::PARAM_INT);
        $stmt->bindValue(5, $key->kdf()->memLimit, \PDO::PARAM_INT);
        $stmt->bindValue(6, $key->kdf()->algorithm, \PDO::PARAM_INT);
        $stmt->bindValue(7, $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Lifts a wrapping to the current KDF profile at login (issue #100):
     * the same key pair under the same password, only with a new salt and
     * new cost factors (App\Service\Crypto\UserKey::upgradedWrapping()).
     *
     * Written only if the row is still exactly the one the login unwrapped
     * (compare-and-swap on public key and wrapped value). A password change
     * or reset that commits in between must win - otherwise this would put
     * back a wrapping under the old password, and the new password would no
     * longer open the vault.
     *
     * @return bool whether the row was replaced
     */
    public function replaceWrapping(int $userId, UserKey $unwrapped, UserKey $upgraded): bool
    {
        if (!hash_equals($unwrapped->publicKey(), $upgraded->publicKey())) {
            throw new \LogicException('An upgraded wrapping has to keep the key pair.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE user_key SET wrapped_private_key = ?, kdf_salt = ?, kdf_ops = ?, kdf_mem = ?, kdf_alg = ?
             WHERE user_id = ? AND public_key = ? AND wrapped_private_key = ?',
        );
        $stmt->bindValue(1, $upgraded->wrappedPrivateKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(2, $upgraded->kdf()->salt, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $upgraded->kdf()->opsLimit, \PDO::PARAM_INT);
        $stmt->bindValue(4, $upgraded->kdf()->memLimit, \PDO::PARAM_INT);
        $stmt->bindValue(5, $upgraded->kdf()->algorithm, \PDO::PARAM_INT);
        $stmt->bindValue(6, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(7, $unwrapped->publicKey(), \PDO::PARAM_LOB);
        $stmt->bindValue(8, $unwrapped->wrappedPrivateKey(), \PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * For the login (M3-3) and for tests that prove the wrapped key actually
     * unwraps with the password it was created with.
     */
    public function forUser(int $userId): ?UserKey
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_key WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return UserKey::fromStorage(
            (string) $row['public_key'],
            (string) $row['wrapped_private_key'],
            KdfParameters::fromStorage(
                (string) $row['kdf_salt'],
                (int) $row['kdf_ops'],
                (int) $row['kdf_mem'],
                (int) $row['kdf_alg'],
            ),
        );
    }
}
