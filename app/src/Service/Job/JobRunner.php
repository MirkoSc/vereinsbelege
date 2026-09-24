<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Berechtigungen;
use App\Domain\JobExecutor;
use App\Repository\JobRepository;
use App\Service\Crypto\Vault;

/**
 * The session-worker side of docs/spec/06-betrieb.md section 4 (issue
 * #29/M4-7): `App\Api\JobController` calls schritt() once per
 * `POST /api/jobs/step`, driven by `public/js/jobs.js` in the browser of a
 * signed-in person. Framework-free like App\Service\Job\JobHandler
 * (CLAUDE.md section 6a) - no Http, no Session; a Berechtigungen and an
 * unlocked Vault are all it needs, and the worker module (07-worker.md)
 * will reuse the handlers this drives without needing either.
 *
 * A job type is only ever claimed for an account whose rights allow it
 * (JobHandler::recht()) - never "any offen job", so a Kassenprüfer's
 * session-worker cannot be made to touch a job only Finanzen may drive.
 */
final readonly class JobRunner
{
    /** Lock length of one step, in seconds - a shared-hosting request's budget (CLAUDE.md section 1) with margin. */
    private const int LOCK_SECONDS = 60;

    /**
     * A job claimed this many times without ever finishing a step has a
     * request that crashes or gets cut off every time - JobRunner gives up
     * on it instead of retrying forever.
     */
    private const int MAX_VERSUCHE = 3;

    /**
     * @param list<JobHandler> $handler every job type this runner may
     *        drive - each declares the right it needs itself
     *        (JobHandler::recht()), so a new type needs no change here.
     */
    public function __construct(
        private JobRepository $jobs,
        private array $handler,
    ) {
    }

    /**
     * Runs exactly one step of the oldest runnable job this account's
     * rights allow, or reports there was nothing to do.
     */
    public function schritt(Berechtigungen $berechtigungen, Vault $vault, \DateTimeImmutable $now): JobLauf
    {
        $typen = $this->erlaubteTypen($berechtigungen);
        if ($typen === []) {
            return JobLauf::leer(0);
        }

        $lockedBy = 'session:' . bin2hex(random_bytes(8));
        $job = $this->jobs->claim(JobExecutor::Session, $lockedBy, self::LOCK_SECONDS, now: $now, typen: $typen);
        if ($job === null) {
            return JobLauf::leer($this->jobs->zaehleOffen(JobExecutor::Session, $typen));
        }

        // A crashed or cut-off request never reaches schrittErledigt()/
        // fail() below, so the next claim of the same job sees `attempts`
        // one higher than last time - a step that succeeds resets it to 0
        // (JobRepository::schrittErledigt()).
        if ($job->attempts > self::MAX_VERSUCHE) {
            $this->jobs->fail($job->id, JobAbgebrochen::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->jobs->zaehleOffen(JobExecutor::Session, $typen));
        }

        try {
            $ergebnis = $this->handlerFuer($job->typ)->schritt($job, $vault, $now);
        } catch (\Throwable $e) {
            // Only the class, never the message (App\Repository\
            // JobRepository::fail() docblock): a database or processing
            // exception can quote the row it failed on.
            $this->jobs->fail($job->id, $e::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->jobs->zaehleOffen(JobExecutor::Session, $typen));
        }

        $this->jobs->schrittErledigt($job->id, $lockedBy, $ergebnis, $now);

        return JobLauf::gearbeitet($this->jobs->zaehleOffen(JobExecutor::Session, $typen));
    }

    /**
     * How many jobs wait for this account's rights - the header count
     * (`public/js/jobs.js`, App\Http\LoginGuard). Null for an account with
     * no right for any job type: the header shows nothing rather than
     * "0 in Verarbeitung" for someone who could never make that number
     * move. Needs no vault: the count is plaintext.
     */
    public function offen(Berechtigungen $berechtigungen): ?int
    {
        $typen = $this->erlaubteTypen($berechtigungen);

        return $typen === [] ? null : $this->jobs->zaehleOffen(JobExecutor::Session, $typen);
    }

    /**
     * @return list<string>
     */
    private function erlaubteTypen(Berechtigungen $berechtigungen): array
    {
        $typen = [];
        foreach ($this->handler as $handler) {
            if ($berechtigungen->darf($handler->recht())) {
                $typen[] = $handler->typ();
            }
        }

        return $typen;
    }

    private function handlerFuer(string $typ): JobHandler
    {
        foreach ($this->handler as $handler) {
            if ($handler->typ() === $typ) {
                return $handler;
            }
        }

        // erlaubteTypen() is exactly where claim()'s $typen came from, so a
        // claimed job's type is always one of $this->handler - this is a
        // programming error, not something a job can trigger.
        throw new \LogicException(sprintf('No handler registered for job type "%s".', $typ));
    }
}
