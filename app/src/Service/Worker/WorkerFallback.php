<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\Worker;
use App\Repository\DocumentArtifactRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Repository\WorkerRepository;
use App\Service\Mail\WorkerAlarm;

/**
 * The way back to the browser/session route (docs/spec/07-worker.md
 * sections 1 and 6, issue #53/M7b-3, E-43): "Fällt der Worker aus,
 * übernimmt nach einer Wartezeit automatisch wieder der Browser-/
 * Session-Weg. Kein Beleg geht verloren oder bleibt hängen."
 *
 * - ausfuehren() (cron, every run, and right after the module is switched
 *   off or the worker unpaired): worker jobs nobody holds go back - all of
 *   them when the module is off, no worker is ready or the worker has been
 *   silent for the fallback period; else those of a type the worker no
 *   longer reports. `render_pages` to the browser, `ai_extract` to the
 *   session, `ocr` ends `uebersprungen` (OCR can be caught
 *   up later). Moved in place by a conditional UPDATE, so a job the worker
 *   claims at the same moment stays with it.
 * - nachFehlschlag(): a worker job that failed for good (or ran out of
 *   attempts) gets its browser/session counterpart queued.
 * - The alarm mail: worker silent for more than ALARM_STUNDEN with the
 *   module on and jobs that were due for it - once per outage.
 *
 * Nothing here decrypts; it runs in the cron (CLAUDE.md section 4).
 */
final readonly class WorkerFallback
{
    public const string GRUND = 'WorkerAusfall';

    public const int ALARM_STUNDEN = 24;

    /** JSON {kennung, jobs}: jobs moved back during the current outage. */
    public const string SETTING_AUSFALL = 'worker_ausfall';

    /** The outage the alarm went out for. */
    public const string SETTING_ALARM_FUER = 'worker_alarm_fuer';

    public function __construct(
        private WorkerRouting $routing,
        private WorkerRepository $workers,
        private JobRepository $jobs,
        private WorkerFreigaben $freigaben,
        private SettingRepository $settings,
        private DocumentArtifactRepository $artifacts,
        private ?WorkerAlarm $alarm = null,
    ) {
    }

    /** The route a worker job type takes without the worker; null = skipped. */
    public static function ersatz(string $typ): ?JobExecutor
    {
        return match ($typ) {
            'render_pages' => JobExecutor::Browser,
            'ai_extract' => JobExecutor::Session,
            default => null,
        };
    }

    /** @return int how many worker jobs went back */
    public function ausfuehren(\DateTimeImmutable $now): int
    {
        $worker = $this->workers->aktueller();
        $alle = $this->routing->fallbackFaellig($worker, $now);
        $typen = $worker === null ? [] : $worker->jobTypen();

        $anzahl = 0;
        foreach ($this->jobs->freieWorkerJobs($now) as $job) {
            if (!$alle && in_array($job->typ, $typen, true)) {
                continue;
            }
            if ($this->jobs->vomWorkerUmleiten($job->id, self::ersatz($job->typ), self::GRUND, $now)) {
                // Its unfinished uploads go, and the grants once no worker
                // job of the document is left (issue #52). What it committed
                // is unchecked - and the moved job keeps its id, so its new
                // run must not take those rows for its own.
                $this->freigaben->nachJob($job);
                $this->artifacts->workerLaufVerwerfen($job->id);
                ++$anzahl;
            }
        }
        if ($anzahl > 0 && $worker !== null) {
            $this->merkeAusfall($worker, $anzahl);
        }
        $this->alarmieren($worker, $now);

        return $anzahl;
    }

    /**
     * A worker job that will not be retried: its committed results are
     * discarded and its step is queued on the browser/session route, unless
     * one is pending already.
     */
    public function nachFehlschlag(Job $job, \DateTimeImmutable $now): void
    {
        // What the failed run committed was never checked (issue #53).
        $this->artifacts->workerLaufVerwerfen($job->id);
        $ziel = self::ersatz($job->typ);
        if ($ziel === null || $job->refType !== 'document' || $job->refId === null
            || $this->jobs->offenFuer([$job->typ], 'document', $job->refId)) {
            return;
        }
        $this->jobs->enqueue($job->typ, $ziel, 'document', $job->refId, now: $now);
    }

    private function alarmieren(?Worker $worker, \DateTimeImmutable $now): void
    {
        if ($this->alarm === null || $worker === null || !$this->routing->modulAktiv() || !WorkerRouting::einsatzbereit($worker)) {
            return;
        }
        $letzter = WorkerRouting::letzteMeldung($worker);
        if ($now->getTimestamp() - $letzter->getTimestamp() < self::ALARM_STUNDEN * 3600) {
            return;
        }
        $kennung = self::kennung($worker);
        if ($this->settings->get(self::SETTING_ALARM_FUER) === $kennung) {
            return;
        }
        $zurueck = $this->ausfallJobs($kennung);
        $wartend = $this->jobs->zaehleFreieWorkerJobs($now);
        if ($zurueck + $wartend === 0) {
            // Nothing was due for the worker - no alarm (section 6: "und
            // Jobs warten").
            return;
        }
        $this->settings->set(self::SETTING_ALARM_FUER, $kennung);
        $this->alarm->senden(intdiv($now->getTimestamp() - $letzter->getTimestamp(), 3600), $zurueck, $wartend, $now);
    }

    private function merkeAusfall(Worker $worker, int $anzahl): void
    {
        $kennung = self::kennung($worker);
        $this->settings->set(self::SETTING_AUSFALL, json_encode(
            ['kennung' => $kennung, 'jobs' => $this->ausfallJobs($kennung) + $anzahl],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function ausfallJobs(string $kennung): int
    {
        $daten = json_decode($this->settings->get(self::SETTING_AUSFALL), true);

        return is_array($daten) && ($daten['kennung'] ?? null) === $kennung && is_int($daten['jobs'] ?? null) ? $daten['jobs'] : 0;
    }

    /** One outage = one worker and its last heartbeat before it went silent. */
    private static function kennung(Worker $worker): string
    {
        return $worker->id . '@' . WorkerRouting::letzteMeldung($worker)->getTimestamp();
    }
}
