<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `trusted_device` (docs/spec/01-sicherheit.md section 3:
 * "'Dieses Gerät 30 Tage merken' (optional, Token gehasht, pro Nutzer
 * widerrufbar)"). `label` is a short, non-identifying description (browser
 * and rough date) shown on the security page so a user can tell which entry
 * to revoke - never anything that could stand in for fingerprinting.
 */
final readonly class TrustedDevice
{
    public function __construct(
        public int $id,
        public string $tokenHash,
        public string $label,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
