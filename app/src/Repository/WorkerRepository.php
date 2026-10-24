<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;

/**
 * The tables `worker` and `worker_pairing` (docs/spec/07-worker.md section
 * 4, issue #51/M7b-1). Operating data, no vault: the access key is
 * server-key ciphertext (`secret_enc`), a pairing code only its HMAC.
 */
final readonly class WorkerRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?Worker
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : Worker::fromRow($row);
    }

    /**
     * The worker that is paired or active, if any - v1 supports one
     * (section 4: "v1 unterstützt einen aktiven").
     */
    public function aktueller(): ?Worker
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker WHERE status <> ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([WorkerStatus::Gesperrt->value]);
        $row = $stmt->fetch();

        return $row === false ? null : Worker::fromRow($row);
    }

    public function anlegen(
        string $name,
        string $publicKey,
        string $fingerprint,
        string $secretEnc,
        int $protokollVersion,
        string $appVersion,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker (name, public_key, key_fingerprint, secret_enc, status, protocol_version, app_version, paired_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $publicKey, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $fingerprint);
        $stmt->bindValue(4, $secretEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(5, WorkerStatus::Gekoppelt->value);
        $stmt->bindValue(6, $protokollVersion, \PDO::PARAM_INT);
        $stmt->bindValue(7, $appVersion);
        $stmt->bindValue(8, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Fingerprint confirmed by an admin: only a worker still waiting for it. */
    public function bestaetigen(int $id, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE worker SET status = ?, confirmed_at = ?, confirmed_by = ? WHERE id = ? AND status = ?',
        );
        $stmt->execute([WorkerStatus::Aktiv->value, $now->format(self::FORMAT), $userId, $id, WorkerStatus::Gekoppelt->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Unpairs: the status AND the key go - a revoked worker cannot sign a
     * single request again, even if the status were ever flipped back.
     */
    public function sperren(int $id, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE worker SET status = ?, secret_enc = NULL, config_sealed = NULL, revoked_at = ?, revoked_by = ?
             WHERE id = ? AND status <> ?',
        );
        $stmt->execute([WorkerStatus::Gesperrt->value, $now->format(self::FORMAT), $userId, $id, WorkerStatus::Gesperrt->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<WorkerFaehigkeit> $faehigkeiten
     */
    public function heartbeat(int $id, int $protokollVersion, string $appVersion, array $faehigkeiten, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE worker SET protocol_version = ?, app_version = ?, capabilities = ?, last_heartbeat_at = ?
             WHERE id = ? AND status <> ?',
        );
        $stmt->execute([
            $protokollVersion,
            $appVersion,
            json_encode(array_map(static fn(WorkerFaehigkeit $f): string => $f->value, $faehigkeiten), JSON_THROW_ON_ERROR),
            $now->format(self::FORMAT),
            $id,
            WorkerStatus::Gesperrt->value,
        ]);
    }

    // --------------------------------------------------------------- pairing

    /**
     * Stores a new pairing code and drops every earlier unused one - only
     * the code shown last can be redeemed.
     */
    public function neuerCode(string $codeHash, \DateTimeImmutable $gueltigBis, ?int $userId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('DELETE FROM worker_pairing WHERE used_at IS NULL')->execute();

        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_pairing (code_hash, expires_at, created_by, created_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $codeHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $gueltigBis->format(self::FORMAT));
        $stmt->bindValue(3, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /** Until when the pending code is valid, or null if there is none. */
    public function offenerCodeBis(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(expires_at) FROM worker_pairing WHERE used_at IS NULL AND expires_at > ?',
        );
        $stmt->execute([$now->format(self::FORMAT)]);
        $bis = $stmt->fetchColumn();

        return is_string($bis) ? new \DateTimeImmutable($bis) : null;
    }

    /**
     * Redeems a code exactly once: a conditional UPDATE whose row count
     * says whether this caller won. Returns the pairing id or null.
     */
    public function codeEinloesen(string $codeHash, \DateTimeImmutable $now): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM worker_pairing WHERE code_hash = ?');
        $stmt->bindValue(1, $codeHash, \PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        $update = $this->pdo->prepare(
            'UPDATE worker_pairing SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
        );
        $update->execute([$now->format(self::FORMAT), (int) $id, $now->format(self::FORMAT)]);

        return $update->rowCount() === 1 ? (int) $id : null;
    }

    public function codeZuordnen(int $pairingId, int $workerId): void
    {
        $this->pdo->prepare('UPDATE worker_pairing SET worker_id = ? WHERE id = ?')->execute([$workerId, $pairingId]);
    }

    /** Cron: pairing codes that expired or were used before $before. */
    public function codesAufraeumen(\DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM worker_pairing WHERE expires_at < ?');
        $stmt->execute([$before->format(self::FORMAT)]);

        return $stmt->rowCount();
    }
}
