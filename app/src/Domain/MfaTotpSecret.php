<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `mfa_totp`, as App\Repository\MfaTotpRepository reads it back.
 * `secretEnc` is server-key ciphertext (docs/spec/01-sicherheit.md section
 * 2) - decrypting it is the caller's moment, the same rule App\Domain\User
 * follows for the address and display name.
 */
final readonly class MfaTotpSecret
{
    public function __construct(
        public string $secretEnc,
        public ?\DateTimeImmutable $confirmedAt,
    ) {
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }
}
