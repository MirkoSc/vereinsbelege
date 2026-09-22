<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Service\Crypto\Vault;

/**
 * What App\Service\Account\PendingLogin::open() hands back: the account
 * whose password was already checked, what the vault did, and where the
 * browser wanted to go before the second factor got in the way.
 */
final readonly class PendingLoginData
{
    public function __construct(
        public int $userId,
        public ?Vault $vault,
        public VaultAccess $vaultAccess,
        public MfaMethod $mfaMethod,
        public ?string $weiter,
    ) {
    }
}
