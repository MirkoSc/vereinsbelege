<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\Worker\WorkerFallback;

/**
 * The automatic way back from the optional worker (issue #53,
 * docs/spec/07-worker.md section 6): worker jobs of a worker that has been
 * silent for the fallback period - or with the module switched off - go
 * back to the browser/session route, and the alarm mail goes out after a
 * day. Runs on every cron call (a few queries, nothing decrypted), so the
 * fallback period is kept to within the cron interval.
 */
final readonly class WorkerFallbackTask implements CronTask
{
    public function __construct(private WorkerFallback $fallback)
    {
    }

    public function name(): string
    {
        return 'worker_fallback';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->fallback->ausfuehren($now);
    }
}
