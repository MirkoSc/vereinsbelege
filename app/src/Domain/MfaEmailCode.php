<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `mfa_email_code` (docs/spec/01-sicherheit.md section 3: "6
 * Ziffern, 10 min gültig, max. 5 Versuche, nur Hash gespeichert"). Only the
 * HMAC of the code is ever stored - see App\Service\Account\MfaService.
 */
final readonly class MfaEmailCode
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        public string $codeHash,
        public \DateTimeImmutable $expiresAt,
        public int $attempts,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
