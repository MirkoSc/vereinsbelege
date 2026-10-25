<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\UploadBudgetRepository;

/**
 * Drops byte budget counters of the public upload whose window has ended
 * (issue #242) - like App\Service\Cron\RateLimitCleanupTask: the key is a
 * hashed address, kept no longer than its window needs it.
 */
final readonly class UploadBudgetCleanupTask implements CronTask
{
    public function __construct(private UploadBudgetRepository $rows)
    {
    }

    public function name(): string
    {
        return 'upload_budget_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->rows->deleteExpired($now);
    }
}
