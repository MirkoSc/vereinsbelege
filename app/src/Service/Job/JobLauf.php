<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Job;

/**
 * What App\Service\Job\JobRunner::schritt() produced (issue #29/M4-7):
 * whether a step ran at all, and how many jobs still wait - the count
 * `public/js/jobs.js` shows in the header.
 *
 * Or (issue #47/M7-5) a step that is only half done: its handler
 * prepared an external call ($aufruf, App\Service\Job\ExternerJobHandler)
 * that App\Api\JobController runs without database and session before it
 * hands the result to JobRunner::nachAufruf() - see aufrufAusstehend().
 */
final readonly class JobLauf
{
    private function __construct(
        public JobLaufStatus $status,
        public int $offen,
        public ?ExternerAufruf $aufruf = null,
        public ?Job $job = null,
        public ?string $lockedBy = null,
    ) {
    }

    /** A job was claimed and its step ran (whatever the result was). */
    public static function gearbeitet(int $offen): self
    {
        return new self(JobLaufStatus::Gearbeitet, $offen);
    }

    /** Nothing to claim, or this account has no right for any job type. */
    public static function leer(int $offen): self
    {
        return new self(JobLaufStatus::Leer, $offen);
    }

    /**
     * A claimed job whose step waits on $aufruf. The job stays locked by
     * $lockedBy (extended to cover the call); `offen` is only known once
     * JobRunner::nachAufruf() has finished the step.
     */
    public static function aufruf(Job $job, string $lockedBy, ExternerAufruf $aufruf): self
    {
        return new self(JobLaufStatus::Gearbeitet, 0, $aufruf, $job, $lockedBy);
    }

    public function aufrufAusstehend(): bool
    {
        return $this->aufruf !== null;
    }
}
