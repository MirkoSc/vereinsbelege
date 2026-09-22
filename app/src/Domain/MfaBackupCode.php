<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `mfa_backup_code` (docs/spec/01-sicherheit.md section 3: "10
 * Einmal-Backup-Codes (gehasht) bei Einrichtung"). `used_at` marks a code as
 * spent instead of deleting the row, so "9 von 10 noch gültig" can be shown
 * without a separate counter (App\App\SecurityController).
 */
final readonly class MfaBackupCode
{
    public function __construct(
        public int $id,
        public string $codeHash,
        public ?\DateTimeImmutable $usedAt,
    ) {
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }
}
