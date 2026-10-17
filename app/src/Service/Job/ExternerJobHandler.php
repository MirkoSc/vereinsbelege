<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Job;
use App\Service\Crypto\Vault;

/**
 * A session job type with steps that wait on something external - the AI
 * provider (issue #47/M7-5, docs/spec/06-betrieb.md section 4). Such a step
 * runs in three phases within one `POST /api/jobs/step`:
 *
 *   1. aufruf(), with the database: reads what the call needs and returns it
 *      as an App\Service\Job\ExternerAufruf - or null, when this step has no
 *      external call (any more), then schritt() runs as for every handler;
 *   2. the call itself, without database and session
 *      (App\Api\JobController releases both first - the host closes a
 *      connection idle for 120 s, an AI call may take longer);
 *   3. nachAufruf(), over a fresh connection: stores the result and says
 *      what comes next.
 *
 * Idempotent like every step: if a request dies between 2 and 3, the job is
 * claimed again later and aufruf() runs again - a handler that already
 * stored the result of this step returns null there instead of calling out
 * a second time.
 */
interface ExternerJobHandler extends JobHandler
{
    /**
     * Phase 1: the external call this step needs, or null when schritt()
     * does the step on its own.
     */
    public function aufruf(Job $job, Vault $vault, \DateTimeImmutable $now): ?ExternerAufruf;

    /**
     * Phase 3: stores what ExternerAufruf::$ausfuehren returned. $job is the
     * row as read again after the call - same step and state as in phase 1,
     * still locked by this request.
     */
    public function nachAufruf(Job $job, Vault $vault, mixed $ergebnis, \DateTimeImmutable $now): JobSchrittErgebnis;
}
