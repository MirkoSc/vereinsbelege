<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Service\Crypto\Vault;

/**
 * The outcome of entering the paper recovery key (issue #22/M3-9,
 * `App\Service\Account\VaultRecovery`).
 *
 * `$grund` is the machine-readable reason behind `$fehler` - `null` on
 * success, one of `App\Service\Crypto\RecoveryKeyProblem::value` when the key
 * itself was rejected, or `rate_limit`/`kein_tresor` for the two failures
 * that never reach `RecoveryKey`. It is what the audit log's "grund" detail
 * stores; the key itself never is.
 */
final readonly class VaultRecoveryResult
{
    private function __construct(
        public ?Vault $vault,
        public bool $freigabeErteilt,
        public ?string $fehler,
        public ?string $grund,
    ) {
    }

    public static function erfolg(Vault $vault, bool $freigabeErteilt): self
    {
        return new self($vault, $freigabeErteilt, null, null);
    }

    public static function fehler(string $fehler, ?string $grund = null): self
    {
        return new self(null, false, $fehler, $grund);
    }

    public function istErfolg(): bool
    {
        return $this->vault !== null;
    }
}
