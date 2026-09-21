<?php

declare(strict_types=1);

namespace App\Service\Cron;

/**
 * One unit of work the cron endpoint runs (docs/spec/06-betrieb.md section 4).
 *
 * A task NEVER decrypts anything: the cron has no user session and so no
 * vault key (CLAUDE.md section 4). Mail dispatch and housekeeping are the
 * only things that belong here. It must also be safe to run again at any
 * time - a run may be cut off by the host, and the next one starts over.
 */
interface CronTask
{
    /** Stable identifier shown in the run result and the log. */
    public function name(): string;

    /**
     * @return int how many units were handled (rows removed, mails sent, ...)
     */
    public function run(\DateTimeImmutable $now): int;
}
