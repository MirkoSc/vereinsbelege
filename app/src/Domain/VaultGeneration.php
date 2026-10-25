<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `vault` with its lifecycle (migration 040, issue #90,
 * docs/spec/01-sicherheit.md section 2 "Schlüsselrotation"): when it was
 * created, when its printed recovery key was confirmed, and when a newer
 * generation replaced it. `publicKeyMac` is the anchor of the public key
 * (migration 047, issue #240) - null when the row has none or the schema
 * predates it; only App\Repository\VaultRepository judges it.
 */
final readonly class VaultGeneration
{
    public function __construct(
        public int $version,
        public string $publicKey,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $recoveryConfirmedAt,
        public ?\DateTimeImmutable $retiredAt,
        public ?string $publicKeyMac = null,
    ) {
    }

    public function aktiv(): bool
    {
        return $this->retiredAt === null;
    }

    public function schluesselBestaetigt(): bool
    {
        return $this->recoveryConfirmedAt !== null;
    }
}
