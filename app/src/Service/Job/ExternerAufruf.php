<?php

declare(strict_types=1);

namespace App\Service\Job;

/**
 * The one long call to something outside (the AI provider) that a job step
 * waits for - docs/spec/06-betrieb.md section 4: a step that waits on
 * something external holds no database connection across the call. So
 * App\Service\Job\ExternerJobHandler::aufruf() only prepares it, with the
 * connection still open; App\Api\JobController then releases the session
 * and the connection, runs $ausfuehren, and hands the result to
 * ExternerJobHandler::nachAufruf() over a fresh connection.
 *
 * $ausfuehren must not touch the database or the session - both are gone
 * while it runs. It may return anything; a \Throwable it throws fails the
 * job with the class name only, like any other step.
 */
final readonly class ExternerAufruf
{
    /**
     * @param \Closure(): mixed $ausfuehren the call itself
     * @param int $hoechstensSekunden how long it may take at most (the AI
     *        profile's time limit) - the job's lock is extended to cover it,
     *        so a second tab does not claim the job while it runs
     */
    public function __construct(
        public \Closure $ausfuehren,
        public int $hoechstensSekunden,
    ) {
        if ($hoechstensSekunden < 1) {
            throw new \InvalidArgumentException('An external call needs a time limit of at least one second.');
        }
    }
}
