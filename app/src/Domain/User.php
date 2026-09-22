<?php

declare(strict_types=1);

namespace App\Domain;

use App\Service\Account\MfaMethod;

/**
 * One row of the `user` table, as App\Repository\UserRepository reads it
 * back (docs/spec/02-datenmodell.md "Benutzer und Sicherheit").
 *
 * The email and the display name stay ENCRYPTED in this object. They are
 * operating data under the server key, not vault data, but decrypting them
 * is still the caller's decision and the caller's moment: the login looks an
 * account up by its blind index and never needs the address back, and the
 * header decrypts only the display name it is about to print. Holding the
 * plaintext here would mean carrying it through every layer that only wanted
 * an id.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $emailEnc,
        public string $displayNameEnc,
        public string $passwordHash,
        public UserStatus $status,
        public ?\DateTimeImmutable $expiresAt,
        public bool $mfaRequired,
        public ?MfaMethod $mfaMethod,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }

    /**
     * Whether a second factor is actually usable, not merely required
     * (docs/spec/01-sicherheit.md section 3, issue #17): `mfa_required`
     * defaults to true for every account from the moment it is created
     * (migrations/006_user.sql), long before anybody set a factor up.
     * App\Http\LoginGuard uses this to force enrollment instead of letting a
     * required-but-absent factor quietly mean "none".
     */
    public function mfaEingerichtet(): bool
    {
        return $this->mfaMethod !== null;
    }

    /**
     * Whether this account may sign in at all - the status check of
     * docs/spec/01-sicherheit.md section 3/4. `expires_at` is the mandatory
     * end date of an external account (Kassenprüfer, Steuerberater); a
     * regular account has none.
     */
    public function mayLogIn(\DateTimeImmutable $now): bool
    {
        return $this->status === UserStatus::Aktiv
            && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'emailEnc' => '*** encrypted ***',
            'displayNameEnc' => '*** encrypted ***',
            'passwordHash' => '*** password hash ***',
            'status' => $this->status->value,
            'expiresAt' => $this->expiresAt?->format('c'),
            'mfaRequired' => $this->mfaRequired,
            'mfaMethod' => $this->mfaMethod?->value,
        ];
    }
}
