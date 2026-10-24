<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * What App\Installer\RestoreAccess::relink() did, for the final page of the
 * installer.
 */
final readonly class RestoreAccessResult
{
    public function __construct(
        public int $userId,
        /** The account had no grant of the current vault and got one. */
        public bool $freigabeErteilt,
        /** The authenticator app was removed; the first login sets 2FA up again. */
        public bool $mfaZurueckgesetzt,
    ) {
    }
}
