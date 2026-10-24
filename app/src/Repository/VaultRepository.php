<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\VaultGeneration;
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

    /**
     * Every generation, oldest first (issue #90).
     *
     * @return list<VaultGeneration>
     */
    public function all(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT version, public_key, created_at, recovery_confirmed_at, retired_at FROM vault ORDER BY version',
            );
        } catch (\PDOException) {
            // Between the updater's 'switch' and 'migrate' steps migration 039
            // has not run yet: no rotation can exist, every generation is
            // active - logins and the update chain must keep working.
            $stmt = $this->pdo->query(
                'SELECT version, public_key, created_at, created_at AS recovery_confirmed_at, NULL AS retired_at FROM vault ORDER BY version',
            );
        }
        if ($stmt === false) {
            return [];
        }

        return array_map(self::generation(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The generations not replaced yet, oldest first. One in normal
     * operation; two while a key rotation runs - that IS the rotation state
     * (migration 039), there is no separate flag to keep in step.
     *
     * @return list<VaultGeneration>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn(VaultGeneration $g): bool => $g->aktiv()));
    }

    public function rotationInProgress(): bool
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM vault WHERE retired_at IS NULL');
        } catch (\PDOException) {
            // Before migration 039 (update between 'switch' and 'migrate'):
            // no rotation, and the update's own pages must stay reachable.
            return false;
        }

        return $stmt !== false && (int) $stmt->fetchColumn() > 1;
    }

    public function generationOf(int $version): ?VaultGeneration
    {
        foreach ($this->all() as $generation) {
            if ($generation->version === $version) {
                return $generation;
            }
        }

        return null;
    }

    /**
     * Which generation a public key belongs to - lets a recovery key of a
     * replaced generation be told apart from one of another installation.
     */
    public function versionForPublicKey(string $publicKey): ?int
    {
        foreach ($this->all() as $generation) {
            if (hash_equals($generation->publicKey, $publicKey)) {
                return $generation->version;
            }
        }

        return null;
    }

    /**
     * The admin typed back the last group of this generation's printed
     * recovery key. Idempotent: the first confirmation stays.
     */
    public function confirmRecovery(int $version, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE vault SET recovery_confirmed_at = ? WHERE version = ? AND recovery_confirmed_at IS NULL',
        );
        $stmt->execute([$now->format(self::FORMAT), $version]);
    }

    /**
     * The generation is replaced. Never deleted: the row carries the foreign
     * keys of history and recognises the paper key of an old backup.
     */
    public function retire(int $version, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE vault SET retired_at = ? WHERE version = ? AND retired_at IS NULL');
        $stmt->execute([$now->format(self::FORMAT), $version]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function generation(array $row): VaultGeneration
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);

        return new VaultGeneration(
            (int) $row['version'],
            (string) $row['public_key'],
            new \DateTimeImmutable((string) $row['created_at']),
            $datum($row['recovery_confirmed_at'] ?? null),
            $datum($row['retired_at'] ?? null),
        );
    }

    /**
     * "Neu beginnen" on the installer's key confirmation page
     * (docs/spec/01-sicherheit.md section 2): the browser was closed before
     * the recovery key was confirmed, so the half-finished generation - this
     * row plus, via the foreign keys in migrations/006_user.sql, its user and
     * grant - is removed. Safe only before config.php exists: nothing else
     * ever deletes a vault generation.
     */
    public function delete(int $version): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM vault WHERE version = ?');
        $stmt->execute([$version]);
    }
}
