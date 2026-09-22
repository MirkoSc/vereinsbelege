<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\RateLimiter;

/**
 * Drops rate limit counters whose window is long over
 * (docs/spec/01-sicherheit.md section 5: the IP is kept as a hash and
 * discarded afterwards).
 *
 * Two reasons this belongs in the cron and not in the login path: a table
 * that only ever grows is a table that eventually costs the login its speed,
 * and a hashed address that no counter reads anymore is data kept for
 * nothing. Nothing here decrypts - the rows hold digests and integers, which
 * is what allows the cron to touch them at all (CLAUDE.md section 4).
 */
final readonly class RateLimitCleanupTask implements CronTask
{
    public function __construct(private RateLimiter $limits)
    {
    }

    public function name(): string
    {
        return 'rate_limit_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->limits->cleanup($now);
    }
}
