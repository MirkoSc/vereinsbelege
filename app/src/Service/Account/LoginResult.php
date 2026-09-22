<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\User;
use App\Service\Crypto\Vault;

/**
 * The outcome of App\Service\Account\LoginService::attempt().
 *
 * Either a failure with a message that gives nothing away (LoginFailure), or
 * a user plus what the session may do with the vault (VaultAccess). The
 * unlocked vault travels in here for exactly one step: the controller takes
 * it, hands it to App\Service\Account\SessionVault and drops it. Nothing
 * stores this object.
 */
final readonly class LoginResult
{
    private function __construct(
        public ?User $user,
        public ?Vault $vault,
        public VaultAccess $vaultAccess,
        public ?LoginFailure $failure,
    ) {
    }

    public static function erfolg(User $user, ?Vault $vault, VaultAccess $vaultAccess): self
    {
        return new self($user, $vault, $vaultAccess, null);
    }

    public static function fehler(LoginFailure $failure): self
    {
        return new self(null, null, VaultAccess::Fehlgeschlagen, $failure);
    }

    /**
     * @phpstan-assert-if-true !null $this->user
     */
    public function istErfolg(): bool
    {
        return $this->failure === null && $this->user !== null;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function __debugInfo(): array
    {
        return [
            'userId' => $this->user?->id,
            'vault' => $this->vault === null ? null : '*** unlocked vault ***',
            'vaultAccess' => $this->vaultAccess->name,
            'failure' => $this->failure?->name,
        ];
    }
}
