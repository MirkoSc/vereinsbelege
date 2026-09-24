<?php

declare(strict_types=1);

namespace App\Service\Job;

/**
 * What App\Service\Job\JobRunner::schritt() produced (issue #29/M4-7):
 * whether a step ran at all, and how many jobs still wait - the count
 * `public/js/jobs.js` shows in the header.
 */
final readonly class JobLauf
{
    private function __construct(
        public JobLaufStatus $status,
        public int $offen,
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
}
