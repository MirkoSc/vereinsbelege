<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `vault` with its lifecycle (migration 042, issue #90,
 * docs/spec/01-sicherheit.md section 2 "Schlüsselrotation"): when it was
 * created, when its printed recovery key was confirmed, and when a newer
 * generation replaced it.
 */
final readonly class VaultGeneration
{
    public function __construct(
        public int $version,
        public string $publicKey,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $recoveryConfirmedAt,
        public ?\DateTimeImmutable $retiredAt,
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
