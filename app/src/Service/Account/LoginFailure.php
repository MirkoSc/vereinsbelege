<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Why a login attempt did not succeed.
 *
 * There are deliberately only two cases, and only one of them is about the
 * credentials: an unknown address, a wrong password, a locked account and an
 * expired external account all end up as `Zugangsdaten` with the very same
 * sentence (docs/spec/01-sicherheit.md section 3, "generische
 * Fehlermeldungen (keine User-Enumeration)"). Anything finer grained would
 * turn the login form into a way of asking the installation who has an
 * account here.
 *
 * "Too many attempts" is the exception, and it has to be: a person who is
 * locked out needs to know that waiting helps, and the answer gives nothing
 * away - it is the same for an address that does not exist.
 */
enum LoginFailure
{
    case Zugangsdaten;
    case ZuVieleVersuche;

    public function meldung(): string
    {
        return match ($this) {
            self::Zugangsdaten => 'E-Mail-Adresse oder Passwort ist falsch.',
            self::ZuVieleVersuche => 'Zu viele Anmeldeversuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
        };
    }
}
