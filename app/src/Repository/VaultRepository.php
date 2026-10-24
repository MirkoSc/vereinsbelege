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
     * The generation everything new is sealed to: the newest one whose
     * recovery key is confirmed - or null when no vault has been set up yet.
     *
     * A key rotation's new generation stays out of this until its printed
     * recovery key is confirmed (issue #90): before that, nothing may depend
     * on a key that exists only in the grants - a password reset of the only
     * holder would take it, and everything sealed to it, with it.
     */
    public function current(): ?Vault
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT version, public_key FROM vault WHERE recovery_confirmed_at IS NOT NULL ORDER BY version DESC LIMIT 1',
            );
        } catch (\PDOException) {
            // Before migration 039 (update between 'switch' and 'migrate').
            $stmt = $this->pdo->query('SELECT version, public_key FROM vault ORDER BY version DESC LIMIT 1');
        }
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return Vault::locked((string) $row['public_key'], (int) $row['version']);
    }

    /**
     * @param bool $schluesselBestaetigt false only for the new generation of a
     *        key rotation, which waits for its recovery key (issue #90). The
     *        installer's first generation counts as confirmed: the
     *        installation cannot finish without the confirmation, and
     *        "Neu beginnen" deletes the row otherwise.
     */
    public function insert(
        string $publicKey,
        int $version = Vault::FIRST_VERSION,
        ?\DateTimeImmutable $now = null,
        bool $schluesselBestaetigt = true,
    ): void {
        $zeit = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        try {
            $stmt = $this->pdo->prepare('INSERT INTO vault (version, public_key, created_at, recovery_confirmed_at) VALUES (?, ?, ?, ?)');
            $stmt->bindValue(4, $schluesselBestaetigt ? $zeit : null, $schluesselBestaetigt ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        } catch (\PDOException $e) {
            // A schema before migration 039 (an installation that migrates in
            // steps): no rotation exists there, the column comes with it.
            if (!$schluesselBestaetigt || !str_contains($e->getMessage(), 'recovery_confirmed_at')) {
                throw $e;
            }
            $stmt = $this->pdo->prepare('INSERT INTO vault (version, public_key, created_at) VALUES (?, ?, ?)');
        }
        $stmt->bindValue(1, $version, \PDO::PARAM_INT);
        $stmt->bindValue(2, $publicKey, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $zeit);
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

    /**
     * Why a release before migration 039 must not run on this database, or
     * null (issue #90, docs/spec/06-betrieb.md section 1 "Rollback"): it
     * takes the newest `vault` row as the key, so the newest row must be a
     * confirmed generation in use, and no rotation may run.
     */
    public function rollbackHindernis(): ?string
    {
        $alle = $this->all();
        $neueste = $alle === [] ? null : $alle[count($alle) - 1];
        if ($this->rotationInProgress() || ($neueste !== null && (!$neueste->aktiv() || !$neueste->schluesselBestaetigt()))) {
            return 'Rollback nicht möglich, solange eine Tresor-Schlüsselrotation läuft. Bitte die Rotation erst abschließen oder abbrechen.';
        }

        return null;
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
            'UPDATE vault SET recovery_confirmed_at = ? WHERE version = ? AND recovery_confirmed_at IS NULL AND retired_at IS NULL',
        );
        $stmt->execute([$now->format(self::FORMAT), $version]);
    }

    /**
     * Locks the generation's row for the caller's transaction; true while its
     * recovery key is still unconfirmed (a rotation may be abandoned only
     * then - issue #90).
     */
    public function sperreUnbestaetigt(int $version): bool
    {
        $stmt = $this->pdo->prepare('SELECT recovery_confirmed_at FROM vault WHERE version = ? AND retired_at IS NULL FOR UPDATE');
        $stmt->execute([$version]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) && $row['recovery_confirmed_at'] === null;
    }

    /**
     * The generation is replaced - or, for an abandoned rotation, never came
     * into use. Never deleted: the row carries the foreign keys of history,
     * recognises the paper key of an old backup, and keeps its version
     * number from being handed out again.
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
     * grant - is removed. Safe only before config.php exists. The one other
     * caller: abandoning a vault key rotation (issue #90) removes its
     * pending generation, which nothing was ever sealed to.
     */
    public function delete(int $version): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM vault WHERE version = ?');
        $stmt->execute([$version]);
    }
}
