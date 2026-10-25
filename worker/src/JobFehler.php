<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * A job cannot be done: reported with `fail` as an identifier, never a
 * message. $endgueltig hands it to the browser/session route at once
 * (WorkerFallback on the web host); otherwise it is retried up to the
 * web host's limit.
 */
final class JobFehler extends \RuntimeException
{
    public function __construct(public readonly string $fehlercode, public readonly bool $endgueltig)
    {
        parent::__construct($fehlercode);
    }
}
