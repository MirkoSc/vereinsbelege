<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Crypto\Vault;

/**
 * The `vault` table: the public key of the current vault generation
 * (docs/spec/01-sicherheit.md section 2).
 *
 * It hands out a *locked* vault, and that is the whole point of the class:
 * sealing works without a secret, so anything that only writes - the chunk
 * upload, the public submission - gets what it needs here, while reading
 * still requires a session that unwrapped VK_priv.
 *
 * Writing the row is the installer's job from M3-2 on; insert() exists for it
 * and for the tests.
 */
final readonly class VaultRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The newest generation, or null when no vault has been set up yet.
     */
    public function current(): ?Vault
    {
        $stmt = $this->pdo->query('SELECT version, public_key FROM vault ORDER BY version DESC LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return Vault::locked((string) $row['public_key'], (int) $row['version']);
    }

    public function insert(
        string $publicKey,
        int $version = Vault::FIRST_VERSION,
        ?\DateTimeImmutable $now = null,
    ): void {
        $stmt = $this->pdo->prepare('INSERT INTO vault (version, public_key, created_at) VALUES (?, ?, ?)');
        $stmt->bindValue(1, $version, \PDO::PARAM_INT);
        $stmt->bindValue(2, $publicKey, \PDO::PARAM_LOB);
        $stmt->bindValue(3, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();
    }
}
