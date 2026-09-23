<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Job;
use App\Service\Crypto\Vault;

/**
 * One job type's logic (docs/spec/06-betrieb.md section 4, CLAUDE.md
 * section 6a): framework-free, so it runs identically whether it is called
 * from a signed-in session's browser worker (M4-7's `POST /api/jobs/step`)
 * or, once a job's own type allows it, the optional worker module.
 *
 * A single call does **one** step and returns - the time budget of a
 * shared-hosting request is short (CLAUDE.md section 1), so a handler with
 * more work than fits pauses in the middle and picks up again at
 * `job.step`/`job.state` next call. Every step is idempotent: calling it
 * again with the same state must not duplicate work or data.
 */
interface JobHandler
{
    /** The `job.typ` this handler is responsible for. */
    public function typ(): string;

    /**
     * Runs the job's current step (`$job->step`, `''` for the first one) and
     * says what happened. The vault is unlocked - only a logged-in session
     * (or, for a worker-eligible type, a worker grant) ever calls this.
     */
    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis;
}
