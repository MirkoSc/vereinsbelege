<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Domain\AuditAction;
use App\Repository\AuditLogRepository;
use App\Repository\RateLimitRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * What happens when the vault anchor does not verify (issue #240): an audit
 * entry `tresor.anker_abweichung` - besides the refusal itself, which is
 * VaultRepository's, and the system check, which looks on its own.
 *
 * At most one entry per generation and hour: the public submission page
 * reaches current() on every visit, and an attacker who broke the anchor
 * must not also get to flood the audit log. Best effort - a failure here
 * never replaces the refusal with another error.
 */
final readonly class TresorAnkerAlarm
{
    public const string LIMIT = 'tresor.anker_abweichung';

    public const int FENSTER_SEKUNDEN = 3600;

    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
    ) {
    }

    public function __invoke(int $version): void
    {
        try {
            $limiter = new RateLimiter(new RateLimitRepository($this->pdo), self::FENSTER_SEKUNDEN);
            if ($limiter->isBlocked(self::LIMIT, (string) $version, 1)) {
                return;
            }
            $limiter->register(self::LIMIT, (string) $version);
            // Without details: those would be sealed to current() - the very
            // key that just failed.
            new AuditLog(new AuditLogRepository($this->pdo), new VaultRepository($this->pdo, $this->crypto->tresorAnker()), $this->crypto)
                ->record(AuditAction::TresorAnkerAbweichung, null, '', $version);
        } catch (\Throwable) {
            // The caller refuses to seal either way.
        }
    }
}
