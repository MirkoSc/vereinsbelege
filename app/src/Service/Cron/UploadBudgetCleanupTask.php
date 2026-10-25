<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\UploadBudgetRepository;
use App\Service\Upload\UploadService;

/**
 * Housekeeping of the public upload's byte budget (issue #242):
 *
 *   - reservations of uploads nobody finished or aborted go back once the
 *     upload itself is gone from `var/tmp` - the same moment
 *     UploadCleanupTask gives up on it (24 h after its newest chunk, not
 *     after its creation), so an upload still being written keeps holding
 *     what it holds. Never before KEEP_HOURS, which also covers the moment
 *     between reserving and opening an upload;
 *   - counters whose window has ended, and open counters back at zero, go
 *     - like App\Service\Cron\RateLimitCleanupTask: the key is a hashed
 *     address, kept no longer than it is needed.
 */
final readonly class UploadBudgetCleanupTask implements CronTask
{
    public function __construct(
        private UploadBudgetRepository $rows,
        private UploadService $uploads,
    ) {
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
            if ($this->uploads->vorhanden($uploadId)) {
                continue;
            }

            $this->rows->offenFreigeben($uploadId);
            $freigegeben++;
        }

        return $freigegeben + $this->rows->deleteExpired($now);
    }
}
