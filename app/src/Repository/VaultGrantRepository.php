<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Crypto\VaultGrant;

/**
 * The `vault_grant` table (docs/spec/02-datenmodell.md): the vault private
 * key sealed to one user (docs/spec/01-sicherheit.md section 2). Written by
 * the installer for the first admin (M3-2); the freigabe flow of M3-7 adds
 * further rows, one per (user, vault generation).
 */
final readonly class VaultGrantRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(int $userId, VaultGrant $grant, ?int $grantedBy = null, ?\DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vault_grant (user_id, vault_version, sealed_private_key, granted_by, granted_at)
             VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $grant->vaultVersion, \PDO::PARAM_INT);
        $stmt->bindValue(3, $grant->sealed(), \PDO::PARAM_LOB);
        if ($grantedBy === null) {
            $stmt->bindValue(4, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(4, $grantedBy, \PDO::PARAM_INT);
        }
        $stmt->bindValue(5, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * The newest generation's grant for a user, or null when none exists -
     * what the login (M3-3) and the crypto round-trip tests need.
     */
    public function forUser(int $userId): ?VaultGrant
    {
        $stmt = $this->pdo->prepare(
            'SELECT sealed_private_key FROM vault_grant WHERE user_id = ? ORDER BY vault_version DESC LIMIT 1',
        );
        $stmt->execute([$userId]);
        $sealed = $stmt->fetchColumn();

        return $sealed === false ? null : VaultGrant::fromStorage((string) $sealed);
    }
}
