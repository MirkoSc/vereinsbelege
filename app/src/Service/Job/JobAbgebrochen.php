<?php

declare(strict_types=1);

namespace App\Service\Job;

/**
 * Marker for `job.last_error` (issue #29/M4-7's JobRunner, docs/spec/
 * 06-betrieb.md section 4): a job whose lock kept expiring without a step
 * ever finishing - the request behind it crashed or was cut off by the
 * webserver every time. Never thrown; only its class name is stored, the
 * same way any other failure is (App\Repository\JobRepository::fail()).
 */
final class JobAbgebrochen extends \RuntimeException
{
}
