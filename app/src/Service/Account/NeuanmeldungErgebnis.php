<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * The outcome of App\Service\Account\Neuanmeldung::pruefe().
 */
enum NeuanmeldungErgebnis
{
    case Bestaetigt;
    case PasswortFalsch;
    case CodeFalsch;
    case ZuVieleVersuche;

    /**
     * The text the form shows. Naming the wrong input is fine here, as on
     * the password change: the session already belongs to this account.
     */
    public function meldung(): ?string
    {
        return match ($this) {
            self::Bestaetigt => null,
            self::PasswortFalsch => 'Das Passwort ist falsch.',
            self::CodeFalsch => 'Der Code ist ungültig oder abgelaufen.',
            self::ZuVieleVersuche => 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
        };
    }
}
