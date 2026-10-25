<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Berechtigungen;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
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
     * Added to an external call's own time limit (ExternerAufruf::
     * $hoechstensSekunden) for the lock that covers it: room for reading the
     * answer and writing the result over a fresh connection afterwards.
     */
    private const int AUFRUF_PUFFER_SEKUNDEN = 60;

    /** @var list<string> background job types (HintergrundJob), left out of the header count */
    private array $hintergrundTypen;

    /**
     * @param list<JobHandler> $handler every session job type this runner
     *        may drive - each declares the right it needs itself
     *        (JobHandler::recht()), so a new type needs no change here.
     * @param list<JobTyp> $browserTypen browser job types this runner never
     *        calls schritt() on (issue #30/M4-8: their steps run in the
     *        browser, driven by App\Api\RasterungController, not here) but
     *        still counts into offen() - the header shows one figure for
     *        "N Belege in Verarbeitung" across both kinds of work.
     */
    public function __construct(
        private JobRepository $jobs,
        private array $handler,
        private array $browserTypen = [],
    ) {
        $this->hintergrundTypen = array_values(array_map(
            static fn(HintergrundJob $h): string => $h->typ(),
            array_filter($handler, static fn(JobHandler $h): bool => $h instanceof HintergrundJob),
        ));
    }

    /**
     * Runs exactly one step of the oldest runnable job this account's
     * rights allow, or reports there was nothing to do. A step that waits on
     * an external call (App\Service\Job\ExternerJobHandler) comes back
     * half done instead - JobLauf::aufrufAusstehend(), finished by
     * nachAufruf().
     */
    public function schritt(Berechtigungen $berechtigungen, Vault $vault, \DateTimeImmutable $now): JobLauf
    {
        $typen = $this->erlaubteTypen($berechtigungen, $this->handler);
        if ($typen === []) {
            return JobLauf::leer(0);
        }

        $lockedBy = 'session:' . bin2hex(random_bytes(8));
        $job = $this->jobs->claim(JobExecutor::Session, $lockedBy, self::LOCK_SECONDS, now: $now, typen: $typen);
        if ($job === null) {
            return JobLauf::leer($this->zaehleSession($typen));
        }

        // A crashed or cut-off request never reaches schrittErledigt()/
        // fail() below, so the next claim of the same job sees `attempts`
        // one higher than last time - a step that succeeds resets it to 0
        // (JobRepository::schrittErledigt()).
        if ($job->attempts > self::MAX_VERSUCHE) {
            $this->jobs->fail($job->id, JobAbgebrochen::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->zaehleSession($typen));
        }

        try {
            $handler = $this->handlerFuer($job->typ);
            $aufruf = $handler instanceof ExternerJobHandler ? $handler->aufruf($job, $vault, $now) : null;
            if ($aufruf !== null) {
                // The call may take longer than a normal step: the lock has
                // to outlast it, or a second tab claims the job meanwhile.
                if (!$this->jobs->heartbeat($job->id, $lockedBy, $aufruf->hoechstensSekunden + self::AUFRUF_PUFFER_SEKUNDEN, $now)) {
                    return JobLauf::gearbeitet($this->zaehleSession($typen));
                }

                return JobLauf::aufruf($job, $lockedBy, $aufruf);
            }
            $ergebnis = $handler->schritt($job, $vault, $now);
        } catch (\Throwable $e) {
            // Only the class, never the message (App\Repository\
            // JobRepository::fail() docblock): a database or processing
            // exception can quote the row it failed on.
            $this->jobs->fail($job->id, $e::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->zaehleSession($typen));
        }

        $this->jobs->schrittErledigt($job->id, $lockedBy, $ergebnis, $now);

        return JobLauf::gearbeitet($this->zaehleSession($typen));
    }

    /**
     * Finishes a step that schritt() left waiting on an external call
     * (JobLauf::aufrufAusstehend(), App\Service\Job\ExternerJobHandler):
     * App\Api\JobController ran the call without database and session and
     * built this runner anew, on a fresh connection (docs/spec/06-betrieb.md
     * section 4). The result is written only while the job is still locked
     * by the request that claimed it - a request that took longer than its
     * lock lost the job to someone else and must not overwrite their work.
     *
     * @param mixed $ergebnis what ExternerAufruf::$ausfuehren returned, or
     *        the \Throwable it threw - that fails the job with its class
     *        name only, like an exception of schritt()
     */
    public function nachAufruf(Berechtigungen $berechtigungen, Vault $vault, JobLauf $lauf, mixed $ergebnis, \DateTimeImmutable $now): JobLauf
    {
        if ($lauf->job === null || $lauf->lockedBy === null) {
            throw new \LogicException('nachAufruf() needs the run of a step that waits on an external call.');
        }
        $typen = $this->erlaubteTypen($berechtigungen, $this->handler);
        $lockedBy = $lauf->lockedBy;

        $job = $this->jobs->find($lauf->job->id);
        if ($job === null || $job->status !== JobStatus::Laeuft || $job->lockedBy !== $lockedBy) {
            return JobLauf::gearbeitet($this->zaehleSession($typen));
        }

        if ($ergebnis instanceof \Throwable) {
            $this->jobs->fail($job->id, $ergebnis::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->zaehleSession($typen));
        }

        try {
            $handler = $this->handlerFuer($job->typ);
            if (!$handler instanceof ExternerJobHandler) {
                throw new \LogicException(sprintf('Job type "%s" has no external calls.', $job->typ));
            }
            $schritt = $handler->nachAufruf($job, $vault, $ergebnis, $now);
        } catch (\Throwable $e) {
            $this->jobs->fail($job->id, $e::class, $now, $lockedBy);

            return JobLauf::gearbeitet($this->zaehleSession($typen));
        }

        $this->jobs->schrittErledigt($job->id, $lockedBy, $schritt, $now);

        return JobLauf::gearbeitet($this->zaehleSession($typen));
    }

    /**
     * How many jobs wait for this account's rights, session and browser
     * jobs together - the header count (`public/js/jobs.js`,
     * `public/js/rasterung.js`, App\Http\LoginGuard). Null for an account
     * with no right for any job type: the header shows nothing rather than
     * "0 in Verarbeitung" for someone who could never make that number
     * move. Needs no vault: the count is plaintext.
     */
    public function offen(Berechtigungen $berechtigungen): ?int
    {
        $sessionTypen = $this->erlaubteTypen($berechtigungen, $this->handler);
        $browserTypen = $this->erlaubteTypen($berechtigungen, $this->browserTypen);
        if ($sessionTypen === [] && $browserTypen === []) {
            return null;
        }

        $summe = 0;
        if ($sessionTypen !== []) {
            $summe += $this->zaehleSession($sessionTypen);
        }
        if ($browserTypen !== []) {
            $summe += $this->jobs->zaehleOffen(JobExecutor::Browser, $browserTypen);
        }

        return $summe;
    }

    /**
     * @param list<JobTyp> $typen
     *
     * @return list<string>
     */
    private function erlaubteTypen(Berechtigungen $berechtigungen, array $typen): array
    {
        $erlaubt = [];
        foreach ($typen as $typ) {
            if ($berechtigungen->darf($typ->recht())) {
                $erlaubt[] = $typ->typ();
            }
        }

        return $erlaubt;
    }

    /**
     * Pending session jobs of $typen for the header count, background job
     * types (HintergrundJob, issue #88) left out.
     *
     * @param list<string> $typen
     */
    private function zaehleSession(array $typen): int
    {
        $gezaehlt = array_values(array_diff($typen, $this->hintergrundTypen));

        return $gezaehlt === [] ? 0 : $this->jobs->zaehleOffen(JobExecutor::Session, $gezaehlt);
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
