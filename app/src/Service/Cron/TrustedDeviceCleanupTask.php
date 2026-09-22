<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\TrustedDeviceRepository;

/**
 * Drops remembered-device rows whose 30 days are over
 * (docs/spec/01-sicherheit.md section 3/5: a hashed token nobody can use
 * anymore is kept for nothing, the same reasoning as
 * App\Service\Cron\RateLimitCleanupTask). Nothing here decrypts - the row
 * holds a digest, a label and two timestamps (CLAUDE.md section 4).
 */
final readonly class TrustedDeviceCleanupTask implements CronTask
{
    public function __construct(private TrustedDeviceRepository $devices)
    {
    }

    public function name(): string
    {
        return 'geraete_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->devices->deleteExpired($now);
    }
}
