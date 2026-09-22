<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\AuthTokenRepository;

/**
 * Drops one-time links whose time is up (issue #18/M3-5, the same reasoning
 * as App\Service\Cron\TrustedDeviceCleanupTask): an expired token no longer
 * opens anything, and its row is only a digest and two timestamps. Nothing
 * here decrypts (CLAUDE.md section 4).
 */
final readonly class AuthTokenCleanupTask implements CronTask
{
    public function __construct(private AuthTokenRepository $tokens)
    {
    }

    public function name(): string
    {
        return 'links_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->tokens->deleteExpired($now);
    }
}
