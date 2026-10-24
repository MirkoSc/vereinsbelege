<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the `worker` table (docs/spec/07-worker.md section 4, issue
 * #51/M7b-1). Plaintext operating data except `secretEnc`, which is
 * server-key ciphertext of the access key.
 */
final readonly class Worker
{
    /**
     * @param list<WorkerFaehigkeit> $faehigkeiten
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $publicKey,
        public string $fingerprint,
        public ?string $secretEnc,
        public WorkerStatus $status,
        public int $protokollVersion,
        public string $appVersion,
        public array $faehigkeiten,
        public ?\DateTimeImmutable $letzterHeartbeat,
        public ?string $configSealed,
        public int $configVersion,
        public \DateTimeImmutable $gekoppeltAm,
        public ?\DateTimeImmutable $bestaetigtAm,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $liste = json_decode((string) $row['capabilities'], true);
        $faehigkeiten = [];
        foreach (is_array($liste) ? $liste : [] as $wert) {
            $faehigkeit = is_string($wert) ? WorkerFaehigkeit::tryFrom($wert) : null;
            if ($faehigkeit !== null) {
                $faehigkeiten[] = $faehigkeit;
            }
        }

        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            publicKey: (string) $row['public_key'],
            fingerprint: (string) $row['key_fingerprint'],
            secretEnc: $row['secret_enc'] === null ? null : (string) $row['secret_enc'],
            status: WorkerStatus::from((string) $row['status']),
            protokollVersion: (int) $row['protocol_version'],
            appVersion: (string) $row['app_version'],
            faehigkeiten: $faehigkeiten,
            letzterHeartbeat: $row['last_heartbeat_at'] === null ? null : new \DateTimeImmutable((string) $row['last_heartbeat_at']),
            configSealed: $row['config_sealed'] === null ? null : (string) $row['config_sealed'],
            configVersion: (int) $row['config_version'],
            gekoppeltAm: new \DateTimeImmutable((string) $row['paired_at']),
            bestaetigtAm: $row['confirmed_at'] === null ? null : new \DateTimeImmutable((string) $row['confirmed_at']),
        );
    }

    /** The job lock prefix of this worker (App\Repository\JobRepository `locked_by`). */
    public function lockPraefix(): string
    {
        return 'w' . $this->id . '-';
    }

    /**
     * @return list<string>
     */
    public function jobTypen(): array
    {
        return WorkerFaehigkeit::jobTypenFuer($this->faehigkeiten);
    }
}
