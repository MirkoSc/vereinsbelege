<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * A job cannot be done - a step or one of its tools failed. Reported with
 * `fail` as an identifier, never a message (and never a tool's output).
 * $endgueltig: the input itself is the problem (a broken or
 * password-protected PDF, nothing OCR can read) - the web host hands the
 * job to the browser/session route at once (WorkerFallback); otherwise it
 * is retried up to the web host's limit.
 */
final class JobFehler extends \RuntimeException
{
    public function __construct(public readonly string $fehlercode, public readonly bool $endgueltig)
    {
        parent::__construct($fehlercode);
    }
}
