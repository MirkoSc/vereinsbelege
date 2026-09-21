<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\JobRepository;

/**
 * Removes finished jobs (`fertig`, `uebersprungen`) after a week. Jobs in
 * `fehler` stay: someone should look at them.
 */
final readonly class JobCleanupTask implements CronTask
{
    public const int KEEP_DAYS = 7;

    public function __construct(private JobRepository $jobs)
    {
    }

    public function name(): string
    {
        return 'jobs_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->jobs->deleteFinishedBefore($now->modify(sprintf('-%d days', self::KEEP_DAYS)));
    }
}
