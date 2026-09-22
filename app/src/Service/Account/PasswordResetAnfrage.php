<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * What App\Service\Account\PasswordReset::request() found out. The
 * controller answers the browser with the same sentence either way (no user
 * enumeration, docs/spec/01-sicherheit.md section 3) - only whether a mail
 * goes out depends on this.
 */
final readonly class PasswordResetAnfrage
{
    private function __construct(
        public bool $zuVieleVersuche,
        public ?int $userId,
        /** Plain token for the mail link - never stored, never logged. */
        public ?string $token,
        /** `user.email_enc`, still encrypted with the server key. */
        public ?string $emailEnc,
    ) {
    }

    public static function link(int $userId, #[\SensitiveParameter] string $token, string $emailEnc): self
    {
        return new self(false, $userId, $token, $emailEnc);
    }

    public static function keinKonto(): self
    {
        return new self(false, null, null, null);
    }

    public static function gesperrt(): self
    {
        return new self(true, null, null, null);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'zuVieleVersuche' => $this->zuVieleVersuche,
            'userId' => $this->userId,
            'token' => $this->token === null ? null : '*** token ***',
            'emailEnc' => $this->emailEnc === null ? null : '*** encrypted ***',
        ];
    }
}
