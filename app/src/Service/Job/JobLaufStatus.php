<?php

declare(strict_types=1);

namespace App\Service\Job;

/**
 * What one App\Service\Job\JobRunner::schritt() call did (issue #29/M4-7).
 * `App\Api\JobController` sends the value straight into the JSON answer
 * `public/js/jobs.js` reads; `gesperrt` (no unlocked vault) is added by the
 * controller, not the runner - JobRunner never runs without a vault to
 * begin with.
 */
enum JobLaufStatus: string
{
    case Gearbeitet = 'gearbeitet';
    case Leer = 'leer';
}
