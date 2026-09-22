<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * The second factor an account has committed to (docs/spec/01-sicherheit.md
 * section 3, issue #17/M3-4). `user.mfa_method` is NULL until one of these is
 * confirmed - see App\Domain\User::mfaEingerichtet().
 *
 * Choosing one does not lock the other one out of the login: a TOTP account
 * may still ask for a mailed code on the confirmation page (the spec's
 * "E-Mail-Code als Alternative"), and the backup codes work regardless of
 * which method is on file. What this enum decides is only which factor the
 * confirmation page asks for *first*, and which one enrollment set up.
 */
enum MfaMethod: string
{
    case Totp = 'totp';
    case EMail = 'email';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Totp => 'Authenticator-App (TOTP)',
            self::EMail => 'Code per E-Mail',
        };
    }
}
