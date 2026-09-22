<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * What a successful login was able to do with the vault
 * (docs/spec/01-sicherheit.md section 2).
 *
 * Being logged in and being able to read receipts are two different things,
 * and the user lifecycle makes that visible: after a password reset the user
 * has a new key pair and no grant yet, so they sign in normally and see
 * nothing until an admin releases the vault to them again (M3-5/M3-7).
 * Saying which of the two happened is what lets the page explain itself
 * instead of looking empty or broken.
 */
enum VaultAccess
{
    /** VK_priv is open in this session. The normal case. */
    case Entsperrt;

    /** No `vault_grant` row: waiting for an admin to release the vault. */
    case KeineFreigabe;

    /**
     * A grant exists but did not open - the wrapped user key or the grant
     * does not match the account anymore. Not a failed login (the password
     * was right), but nothing in the vault is readable either, and it needs
     * a human rather than another attempt.
     */
    case Fehlgeschlagen;

    public function meldung(): ?string
    {
        return match ($this) {
            self::Entsperrt => null,
            self::KeineFreigabe => 'Ihr Zugang ist noch nicht für den Tresor freigegeben. '
                . 'Ein Administrator muss die Freigabe erteilen, bis dahin bleiben Belege verborgen.',
            self::Fehlgeschlagen => 'Der Tresor konnte für Ihren Zugang nicht entsperrt werden. '
                . 'Bitte wenden Sie sich an einen Administrator.',
        };
    }
}
