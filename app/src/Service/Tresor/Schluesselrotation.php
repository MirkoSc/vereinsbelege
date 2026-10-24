<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Domain\AuditAction;
use App\Domain\VaultGeneration;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Repository\VaultRotationRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;

/**
 * Vault key rotation (issue #90, docs/spec/01-sicherheit.md section 2
 * "Schlüsselrotation", decision E-32): a new vault key pair replaces the old
 * one, as an idempotent chain of short requests driven by the admin's
 * browser (CLAUDE.md section 1).
 *
 *   starten()       one transaction: generation N+1, and every grant of N
 *                   sealed to the same user's public key under N+1 as well -
 *                   box_seal needs no password, so nobody has to log in for
 *                   it. From this moment everything new is sealed to N+1
 *                   (VaultRepository::current() is the newest generation),
 *                   the public submission included.
 *   bestaetigen()   the admin types back the last group of the NEW recovery
 *                   key - proof it was written down, as in the installer.
 *   umschluesseln() one step: re-seals the data keys (`dek_sealed`) of a
 *                   batch of rows from N to N+1 and recomputes their blind
 *                   indexes, row by row, each row in its own transaction.
 *                   The data itself is not re-encrypted: the DEKs stay, only
 *                   who can open them changes.
 *   abschliessen()  only when confirmed AND nothing is left: the grants of N
 *                   are deleted, N is marked retired, every session ends.
 *
 * Everything that decrypts runs in the admin's session with the key ring
 * (App\Service\Crypto\Vault with N as previous generation) - never in cron.
 * Nothing is lost on the way: until the last step every row opens with one
 * of the two keys, and every user holds both.
 *
 * What the rotation does NOT reach, by design (spec): backups made before
 * it - they keep their old keys, and the OLD recovery key stays needed for
 * them; the original `audit_log.dek_sealed` (inside the hash chain, see
 * migration 039), which the old key still opens.
 */
final readonly class Schluesselrotation
{
    /** Rows per query; each is its own short transaction. */
    private const int BATCH = 50;

    /**
     * @param array<string, Indexpflege> $indexpflegen table => handler (Indexpflegen::alle())
     */
    public function __construct(
        private \PDO $pdo,
        private VaultRepository $vaults,
        private VaultGrantRepository $grants,
        private UserRepository $users,
        private VaultRotationRepository $rotation,
        private array $indexpflegen,
        private AuditLog $audit,
    ) {
    }

    /**
     * The current state, counted fresh.
     *
     * @param array{defekt?: int, durchlaufen?: bool} $fortschritt what the
     *        session remembers of earlier steps
     */
    public function stand(array $fortschritt = []): RotationStand
    {
        $aktiv = $this->vaults->active();
        $neu = $aktiv === [] ? null : $aktiv[count($aktiv) - 1];
        if ($neu === null) {
            return new RotationStand(false, null, 0, false);
        }
        if (count($aktiv) < 2) {
            return new RotationStand(false, null, $neu->version, $neu->schluesselBestaetigt());
        }

        $alt = $aktiv[count($aktiv) - 2];
        $offen = [];
        foreach ($this->rotation->tabellen() as $tabelle => $_) {
            $anzahl = $this->rotation->anzahl($tabelle, $alt->version);
            if ($anzahl > 0) {
                $offen[$tabelle] = $anzahl;
            }
        }

        return new RotationStand(
            true,
            $alt->version,
            $neu->version,
            $neu->schluesselBestaetigt(),
            $offen,
            (int) ($fortschritt['defekt'] ?? 0),
            (bool) ($fortschritt['durchlaufen'] ?? false),
        );
    }

    /**
     * Creates generation N+1 and seals it to every holder of N.
     *
     * @param Vault $sitzung the admin's unlocked vault - must be the newest
     *        generation (who holds an older one has nothing to rotate)
     *
     * @return Vault the key ring the admin's session continues with: N+1,
     *         with N as previous generation
     *
     * @throws RotationException when a rotation runs already, the session
     *         vault is not the current one, or the version range is used up
     */
    public function starten(Vault $sitzung, int $userId, string $ip, \DateTimeImmutable $now): Vault
    {
        $aktiv = $this->vaults->active();
        if (count($aktiv) !== 1) {
            throw new RotationException('Es läuft bereits eine Schlüsselrotation.');
        }
        $alt = $aktiv[0];
        if (!$sitzung->isUnlocked() || $sitzung->version !== $alt->version || !hash_equals($alt->publicKey, $sitzung->publicKey())) {
            throw new RotationException('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte neu anmelden.');
        }
        if ($alt->version >= Vault::MAX_VERSION) {
            throw new RotationException('Die Anzahl möglicher Tresor-Generationen ist ausgeschöpft.');
        }

        $neu = Vault::create($alt->version + 1);
        $this->pdo->beginTransaction();
        try {
            $this->vaults->insert($neu->publicKey(), $neu->version, $now);
            // Read under lock, inside the transaction: a grant of N that
            // commits meanwhile waits and is caught by abschliessen().
            $inhaber = $this->grants->holdersOf($alt->version, sperren: true);
            foreach ($inhaber as $h) {
                // The original grantor stays on record.
                $this->grants->insert($h['userId'], VaultGrant::seal($neu, $h['publicKey']), $h['grantedBy'], $now);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            // Two admins pressing at once: the second insert of the same
            // version collides on the primary key.
            if ($e->getCode() === '23000') {
                throw new RotationException('Es läuft bereits eine Schlüsselrotation.');
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->audit->record(AuditAction::TresorRotationGestartet, $userId, $ip, $neu->version, [
            'alte_version' => $alt->version,
            'freigaben' => count($inhaber),
        ], $now);

        return $neu->withPrevious($sitzung->withoutPrevious());
    }

    /**
     * The new recovery key, while the rotation is not finished - computable
     * from the session's key ring, so a closed tab does not lose it before
     * it was written down.
     *
     * @throws RotationException when no rotation runs or the ring does not
     *         hold the new generation
     */
    public function neuerWiederherstellungsschluessel(Vault $ring): RecoveryKey
    {
        $neu = $this->neueGeneration();
        if ($ring->version !== $neu->version || !hash_equals($neu->publicKey, $ring->publicKey())) {
            throw new RotationException('Ihre Sitzung hält den neuen Tresor-Schlüssel nicht. Bitte neu anmelden.');
        }

        return RecoveryKey::forVault($ring->withoutPrevious());
    }

    /**
     * @return bool false when the group does not match
     *
     * @throws RotationException
     */
    public function bestaetigen(Vault $ring, #[\SensitiveParameter] string $letzteGruppe, int $userId, string $ip, \DateTimeImmutable $now): bool
    {
        $schluessel = $this->neuerWiederherstellungsschluessel($ring);
        $erwartet = RecoveryKey::normalizeGroup($schluessel->lastGroup());
        if (!hash_equals($erwartet, RecoveryKey::normalizeGroup($letzteGruppe))) {
            return false;
        }

        if ($this->neueGeneration()->recoveryConfirmedAt === null) {
            $this->vaults->confirmRecovery($ring->version, $now);
            $this->audit->record(AuditAction::TresorRotationSchluesselBestaetigt, $userId, $ip, $ring->version, now: $now);
        }

        return true;
    }

    /**
     * One step of the chain: re-seals rows until about $budget seconds are
     * used. Safe to repeat and to run from two tabs - every row is locked,
     * re-checked and written in its own transaction.
     *
     * @param array{cursor?: array<string, int>, defekt?: int, durchlaufen?: bool} $fortschritt
     *        the session's memory of earlier steps (where each table stands,
     *        rows that do not open) - losing it only means starting the scan
     *        over, never doing a row twice
     *
     * @return array{0: RotationStand, 1: array{cursor: array<string, int>, defekt: int, durchlaufen: bool}}
     *
     * @throws RotationException
     */
    public function umschluesseln(Vault $ring, array $fortschritt, \DateTimeImmutable $now, float $budget = 10.0): array
    {
        [$alt] = $this->pruefeRing($ring);
        $ende = microtime(true) + $budget;
        $cursor = $fortschritt['cursor'] ?? [];
        $defekt = (int) ($fortschritt['defekt'] ?? 0);
        $durchlaufen = true;
        // At least one row per step, whatever the budget: a step that never
        // gets anything done would leave the browser looping.
        $erledigt = 0;

        foreach ($this->rotation->tabellen() as $tabelle => $pk) {
            while (true) {
                if ($erledigt > 0 && microtime(true) >= $ende) {
                    $durchlaufen = false;
                    break 2;
                }
                $ids = $this->rotation->naechste($tabelle, $pk, $alt, $cursor[$tabelle] ?? 0, self::BATCH);
                if ($ids === []) {
                    break;
                }
                foreach ($ids as $id) {
                    if (!$this->zeile($ring, $tabelle, $pk, $id, $alt, $now)) {
                        $defekt++;
                    }
                    $cursor[$tabelle] = $id;
                    $erledigt++;
                    if (microtime(true) >= $ende) {
                        $durchlaufen = false;
                        break 3;
                    }
                }
            }
        }

        $fortschritt = ['cursor' => $cursor, 'defekt' => $defekt, 'durchlaufen' => $durchlaufen];
        $stand = $this->stand($fortschritt);
        // A full pass, yet more is open than failed: rows appeared behind a
        // cursor (written by a request that started before the rotation).
        // Scan again from the start.
        if ($durchlaufen && $stand->offen() > $defekt) {
            $fortschritt = ['cursor' => [], 'defekt' => 0, 'durchlaufen' => false];
            $stand = $this->stand($fortschritt);
        }

        return [$stand, $fortschritt];
    }

    /**
     * The last step: the old generation goes.
     *
     * @return array{tresor: Vault, defekt: array<string, int>} the ring
     *         without the old key, and rows that stayed unreadable
     *
     * @throws RotationException when the key is not confirmed or rows that
     *         the old key opens are still left
     */
    public function abschliessen(Vault $ring, int $userId, string $ip, \DateTimeImmutable $now): array
    {
        [$alt, $neu] = $this->pruefeRing($ring);
        if ($this->neueGeneration()->recoveryConfirmedAt === null) {
            throw new RotationException('Bitte zuerst den neuen Wiederherstellungsschlüssel bestätigen.');
        }

        $this->pdo->beginTransaction();
        try {
            // What is left must be rows the old key does not open either -
            // checked here, under lock and in the same transaction that
            // retires the key, not taken from the session's memory.
            $defekt = [];
            foreach ($this->rotation->tabellen() as $tabelle => $pk) {
                $nach = 0;
                while (($ids = $this->rotation->naechste($tabelle, $pk, $alt, $nach, self::BATCH)) !== []) {
                    foreach ($ids as $id) {
                        $zeile = $this->rotation->sperre($tabelle, $pk, $id);
                        $nach = $id;
                        if ($zeile === null || !is_string($zeile['__dek'] ?? null)) {
                            continue;
                        }
                        try {
                            $ring->openDataKey($zeile['__dek']);
                        } catch (CryptoException) {
                            $defekt[$tabelle] = ($defekt[$tabelle] ?? 0) + 1;
                            continue;
                        }

                        throw new RotationException('Es sind noch Daten mit dem alten Schlüssel versiegelt. Bitte die Umschlüsselung fortsetzen.');
                    }
                }
            }

            // Whoever got a grant of N after the start (granted just before
            // the guard closed the pages) gets one of N+1 now - nobody loses
            // access with the old generation.
            $neueInhaber = array_column($this->grants->holdersOf($neu, sperren: true), 'userId');
            foreach ($this->grants->holdersOf($alt, sperren: true) as $h) {
                if (!in_array($h['userId'], $neueInhaber, true)) {
                    $this->grants->insert($h['userId'], VaultGrant::seal($ring->withoutPrevious(), $h['publicKey']), $h['grantedBy'], $now);
                }
            }

            $this->grants->deleteVersion($alt);
            $this->vaults->retire($alt, $now);
            $this->users->bumpSessionEpochAll();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->audit->record(AuditAction::TresorRotationAbgeschlossen, $userId, $ip, $neu, [
            'alte_version' => $alt,
            'unlesbar' => $defekt,
        ], $now);

        return ['tresor' => $ring->withoutPrevious(), 'defekt' => $defekt];
    }

    /**
     * One row: lock, re-check, open with the old key, seal with the new one,
     * recompute its blind indexes - all or nothing.
     *
     * @return bool false when the row's data key does not open (damaged)
     */
    private function zeile(Vault $ring, string $tabelle, string $pk, int $id, int $alt, \DateTimeImmutable $now): bool
    {
        $this->pdo->beginTransaction();
        try {
            $zeile = $this->rotation->sperre($tabelle, $pk, $id);
            $sealed = $zeile['__dek'] ?? null;
            if ($zeile === null || !is_string($sealed) || strlen($sealed) <= 1 || Vault::sealedVersion($sealed) !== $alt) {
                // Gone or done meanwhile - another tab, another step.
                $this->pdo->commit();

                return true;
            }
            try {
                $dek = $ring->openDataKey($sealed);
            } catch (CryptoException) {
                $this->pdo->rollBack();

                return false;
            }
            unset($zeile['__dek']);

            ($this->indexpflegen[$tabelle] ?? null)?->pflege($id, $zeile, $ring, $alt, $now);
            $this->rotation->schreibe($tabelle, $pk, $id, $ring->sealDataKey($dek));
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return array{0: int, 1: int} old and new version
     *
     * @throws RotationException
     */
    private function pruefeRing(Vault $ring): array
    {
        $aktiv = $this->vaults->active();
        if (count($aktiv) !== 2) {
            throw new RotationException('Es läuft keine Schlüsselrotation.');
        }
        [$alt, $neu] = $aktiv;
        if (
            $ring->version !== $neu->version
            || !hash_equals($neu->publicKey, $ring->publicKey())
            || !$ring->canOpen($alt->version)
        ) {
            throw new RotationException('Ihre Sitzung hält nicht beide Tresor-Schlüssel. Bitte neu anmelden.');
        }

        return [$alt->version, $neu->version];
    }

    /**
     * @throws RotationException
     */
    private function neueGeneration(): VaultGeneration
    {
        $aktiv = $this->vaults->active();
        if (count($aktiv) !== 2) {
            throw new RotationException('Es läuft keine Schlüsselrotation.');
        }

        return $aktiv[1];
    }
}
