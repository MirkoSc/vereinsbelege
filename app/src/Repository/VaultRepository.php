<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\VaultGeneration;
use App\Service\Crypto\TresorAnker;
use App\Service\Crypto\TresorAnkerException;
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
 * And because sealing needs nothing but this row, the row is anchored
 * outside the database (issue #240, finding N-01): with a TresorAnker -
 * derived from the server key in shared/config.php - current() hands out a
 * key only when its `public_key_mac` verifies. Every instance the
 * application builds gets one (tests/Support/TresorAnkerPflichtTest checks
 * app/src); without one, as in unit tests that only seed rows, nothing is
 * checked and insert() writes no MAC.
 *
 * Writing the row is the installer's job from M3-2 on; insert() exists for it
 * and for the tests.
 */
final readonly class VaultRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param (\Closure(int): void)|null $beiAbweichung told the generation
     *        whose anchor failed, before current() refuses (audit entry,
     *        App\Service\Tresor\TresorAnkerAlarm); must not throw
     */
    public function __construct(
        private \PDO $pdo,
        private ?TresorAnker $anker = null,
        private ?\Closure $beiAbweichung = null,
    ) {
    }

    /**
     * The generation everything new is sealed to: the newest one whose
     * recovery key is confirmed - or null when no vault has been set up yet.
     *
     * A key rotation's new generation stays out of this until its printed
     * recovery key is confirmed (issue #90): before that, nothing may depend
     * on a key that exists only in the grants - a password reset of the only
     * holder would take it, and everything sealed to it, with it.
     *
     * @throws TresorAnkerException when the key does not carry a valid
     *         anchor (issue #240) - nothing may be sealed to it
     */
    public function current(): ?Vault
    {
        $generation = $this->neuesteBestaetigte();
        if ($generation === null) {
            return null;
        }
        if (!$this->pruefe($generation)) {
            throw TresorAnkerException::abweichung($generation->version);
        }

        return Vault::locked($generation->publicKey, $generation->version);
    }

    /**
     * current() for the places that answer "no vault" in their own words
     * (public submission, internal capture, upload, worker): a key whose
     * anchor fails counts as none - reported like in current(), then null.
     * Those callers refuse with their structured "kein Tresor" error
     * instead of a bare 500; nothing is sealed either way.
     */
    public function zumVersiegeln(): ?Vault
    {
        try {
            return $this->current();
        } catch (TresorAnkerException) {
            return null;
        }
    }

    /**
     * current() without the anchor check - only for callers that prove the
     * key themselves with the paper recovery key (it contains VK_priv, and
     * VK_pub follows from it): /admin/wiederherstellen and the installer's
     * access step. They re-anchor a key they proved (beglaubige()), which is
     * how an installation gets out of a failed check.
     */
    public function currentUngeprueft(): ?Vault
    {
        $generation = $this->neuesteBestaetigte();

        return $generation === null ? null : Vault::locked($generation->publicKey, $generation->version);
    }

    /**
     * Whether the generation's public key carries a valid anchor. True when
     * this instance has no anchor to check against (see the class comment).
     */
    public function beglaubigt(VaultGeneration $generation): bool
    {
        return $this->anker === null || $this->anker->passt($generation->version, $generation->publicKey, $generation->publicKeyMac);
    }

    /**
     * beglaubigt(), and a failure is reported (audit entry) - for the
     * places that are about to use the key: current() and the login.
     */
    public function pruefe(VaultGeneration $generation): bool
    {
        if ($this->beglaubigt($generation)) {
            return true;
        }
        if ($this->beiAbweichung !== null) {
            ($this->beiAbweichung)($generation->version);
        }

        return false;
    }

    /**
     * Anchors a key the caller has PROVED: generated it in this request
     * (installer, rotation - insert() does that by itself) or derived it from
     * the paper recovery key. Never for a key that was merely read from the
     * database - that is exactly what an attacker would have written.
     * Matches version AND key, so a row that changed in between keeps its
     * old MAC.
     */
    public function beglaubige(int $version, string $publicKey): void
    {
        $stmt = $this->pdo->prepare('UPDATE vault SET public_key_mac = ? WHERE version = ? AND public_key = ?');
        $stmt->bindValue(1, $this->ankerOderFehler()->fuer($version, $publicKey), \PDO::PARAM_LOB);
        $stmt->bindValue(2, $version, \PDO::PARAM_INT);
        $stmt->bindValue(3, $publicKey, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * Trust on first use, for installations from before the anchor existed
     * (issue #240): every generation WITHOUT a MAC gets one, as it stands.
     * Only App\Service\Tresor\Erstbeglaubigung calls this, and only in the
     * request that applied migration 046 - a MAC that goes missing later is
     * not filled in again, it stays a failed check until a recovery key
     * proves the key. A row whose MAC is set but wrong is never touched.
     *
     * @return list<int> the generations anchored
     */
    public function erstbeglaubigen(): array
    {
        $anker = $this->ankerOderFehler();
        $stmt = $this->pdo->query('SELECT version, public_key FROM vault WHERE public_key_mac IS NULL ORDER BY version');
        $versionen = [];
        $update = $this->pdo->prepare('UPDATE vault SET public_key_mac = ? WHERE version = ? AND public_key = ? AND public_key_mac IS NULL');
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $version = (int) $row['version'];
            $publicKey = (string) $row['public_key'];
            $update->bindValue(1, $anker->fuer($version, $publicKey), \PDO::PARAM_LOB);
            $update->bindValue(2, $version, \PDO::PARAM_INT);
            $update->bindValue(3, $publicKey, \PDO::PARAM_LOB);
            $update->execute();
            $versionen[] = $version;
        }

        return $versionen;
    }

    /**
     * The anchor state of every generation still in use, for the system
     * check: version => valid. Retired generations are left out - nothing is
     * sealed to them any more.
     *
     * @return array<int, bool>
     */
    public function ankerStand(): array
    {
        $stand = [];
        foreach ($this->active() as $generation) {
            $stand[$generation->version] = $this->beglaubigt($generation);
        }

        return $stand;
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
        if ($this->anker !== null) {
            // The key was generated by the caller in this request - the one
            // moment its MAC can be written without trusting the database.
            $stmt = $this->pdo->prepare(
                'INSERT INTO vault (version, public_key, public_key_mac, created_at, recovery_confirmed_at) VALUES (?, ?, ?, ?, ?)',
            );
            $stmt->bindValue(1, $version, \PDO::PARAM_INT);
            $stmt->bindValue(2, $publicKey, \PDO::PARAM_LOB);
            $stmt->bindValue(3, $this->anker->fuer($version, $publicKey), \PDO::PARAM_LOB);
            $stmt->bindValue(4, $zeit);
            $stmt->bindValue(5, $schluesselBestaetigt ? $zeit : null, $schluesselBestaetigt ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
            $stmt->execute();

            return;
        }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO vault (version, public_key, created_at, recovery_confirmed_at) VALUES (?, ?, ?, ?)');
            $stmt->bindValue(4, $schluesselBestaetigt ? $zeit : null, $schluesselBestaetigt ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        } catch (\PDOException $e) {
            // A schema before migration 040 (an installation that migrates in
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
        $stmt = $this->ersteGelungene([
            'SELECT version, public_key, public_key_mac, created_at, recovery_confirmed_at, retired_at FROM vault ORDER BY version',
            // Before migration 046 (update between 'switch' and 'migrate'):
            // no MAC - with an anchor configured nothing verifies then, so
            // nothing is sealed for those few seconds.
            'SELECT version, public_key, NULL AS public_key_mac, created_at, recovery_confirmed_at, retired_at FROM vault ORDER BY version',
            // Before migration 040: no rotation can exist, every generation
            // is active - logins and the update chain must keep working.
            'SELECT version, public_key, NULL AS public_key_mac, created_at, created_at AS recovery_confirmed_at, NULL AS retired_at FROM vault ORDER BY version',
        ]);

        return $stmt === null ? [] : array_map(self::generation(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The generations not replaced yet, oldest first. One in normal
     * operation; two while a key rotation runs - that IS the rotation state
     * (migration 040), there is no separate flag to keep in step.
     *
     * @return list<VaultGeneration>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn(VaultGeneration $g): bool => $g->aktiv()));
    }

    /**
     * Why a release before migration 040 must not run on this database, or
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
            // Before migration 040 (update between 'switch' and 'migrate'):
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
            isset($row['public_key_mac']) ? (string) $row['public_key_mac'] : null,
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

    /**
     * The newest generation with a confirmed recovery key - what current()
     * is about. One row, not all(): it runs for every sealed audit detail
     * and every submission.
     */
    private function neuesteBestaetigte(): ?VaultGeneration
    {
        $abfragen = [
            'SELECT version, public_key, public_key_mac, created_at, recovery_confirmed_at, retired_at FROM vault WHERE recovery_confirmed_at IS NOT NULL ORDER BY version DESC LIMIT 1',
            // Before migration 046 (between the updater's 'switch' and
            // 'migrate'): no MAC, so with an anchor nothing verifies.
            'SELECT version, public_key, NULL AS public_key_mac, created_at, recovery_confirmed_at, retired_at FROM vault WHERE recovery_confirmed_at IS NOT NULL ORDER BY version DESC LIMIT 1',
            // Before migration 040: every generation counts as confirmed.
            'SELECT version, public_key, NULL AS public_key_mac, created_at, created_at AS recovery_confirmed_at, NULL AS retired_at FROM vault ORDER BY version DESC LIMIT 1',
        ];
        $row = $this->ersteGelungene($abfragen)?->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::generation($row) : null;
    }

    /**
     * Runs the first query the schema can answer - the newest one first,
     * then those for older schemas (an update between 'switch' and
     * 'migrate' runs the new code on the old schema).
     *
     * @param non-empty-list<string> $abfragen
     */
    private function ersteGelungene(array $abfragen): ?\PDOStatement
    {
        foreach ($abfragen as $i => $sql) {
            try {
                $stmt = $this->pdo->query($sql);

                return $stmt === false ? null : $stmt;
            } catch (\PDOException $e) {
                if ($i === count($abfragen) - 1) {
                    throw $e;
                }
            }
        }

        return null;
    }

    private function ankerOderFehler(): TresorAnker
    {
        return $this->anker ?? throw new \LogicException('This VaultRepository has no vault anchor (server key) to write one with.');
    }
}
