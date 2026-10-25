<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\UploadBudgetRepository;

/**
 * Housekeeping of the public upload's byte budget (issue #242):
 *
 *   - reservations of uploads nobody finished or aborted go back once the
 *     upload cleanup gives up on them as well (UploadCleanupTask::KEEP_HOURS)
 *     - an address that only announced gets its open budget back;
 *   - counters whose window has ended, and open counters back at zero, go
 *     - like App\Service\Cron\RateLimitCleanupTask: the key is a hashed
 *     address, kept no longer than it is needed.
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
        $freigegeben = 0;
        $grenze = $now->setTimestamp($now->getTimestamp() - UploadCleanupTask::KEEP_HOURS * 3600);
        foreach ($this->rows->abgelaufeneReservierungen($grenze) as $uploadId) {
            $this->rows->offenFreigeben($uploadId);
            $freigegeben++;
        }

        return $freigegeben + $this->rows->deleteExpired($now);
    }
}
