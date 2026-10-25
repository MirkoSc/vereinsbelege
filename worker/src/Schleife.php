<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Worker\WorkerProtokoll;
use App\Worker\Http\HttpFehler;
use App\Worker\Schritt\Schritt;

/**
 * The worker's polling loop (docs/spec/07-worker.md section 5): outgoing
 * requests only, no open port.
 *
 *   heartbeat → (new AI configuration) → claim while jobs wait → process
 *   one job after the other → straight on while there is work, else wait
 *   `poll_sekunden` (60 s).
 *
 * Errors (web host unreachable, 5xx, protocol mismatch, unpaired) never
 * end the loop: it backs off - 5 s, 10 s, 20 s ... up to 5 min, with some
 * jitter - and starts over; the first good heartbeat resets it.
 *
 * SIGTERM/SIGINT (Abbruch): no new claim; a running job is cut off (its
 * tool terminated, its HTTP transfer aborted) and given back with `fail`
 * without `endgueltig` - the web host offers it again, to this worker
 * after its restart or, after the waiting time, to the browser/session.
 */
final class Schleife
{
    public const int BACKOFF_START_SEKUNDEN = 5;

    public const int BACKOFF_MAX_SEKUNDEN = 300;

    /** Seconds a job that is given back on shutdown may take to say so. */
    private const int RUECKGABE_ZEITLIMIT = 10;

    private int $fehlerInFolge = 0;

    private ?string $letzterStatus = null;

    /** @var array<string, Schritt> */
    private array $schritte = [];

    /**
     * @param list<Schritt> $schritte
     * @param list<string> $faehigkeiten what the worker reports (`ocr`, `render`, `ai`)
     * @param \Closure(): int $uhr
     * @param \Closure(float): bool $schlafen false when it woke for a stop request
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly Identitaet $identitaet,
        private readonly KiKonfiguration $ki,
        private readonly Arbeitsverzeichnis $arbeit,
        array $schritte,
        private readonly array $faehigkeiten,
        private readonly string $version,
        private readonly Abbruch $abbruch,
        private readonly Log $log,
        private readonly \Closure $uhr,
        private readonly \Closure $schlafen,
    ) {
        foreach ($schritte as $schritt) {
            $this->schritte[$schritt->typ()] = $schritt;
        }
    }

    /** Runs until a stop is requested (or $runden rounds passed - tests). */
    public function laufen(?int $runden = null): void
    {
        $this->log->ereignis('worker_start', ['worker' => $this->identitaet->workerId, 'version' => $this->version, 'aufgeraeumt' => $this->arbeit->aufraeumen()]);
        for ($runde = 0; !$this->abbruch->angefordert() && ($runden === null || $runde < $runden); ++$runde) {
            $pause = $this->runde();
            if ($pause > 0) {
                ($this->schlafen)($pause);
            }
        }
        // The AI keys go with the process anyway; said explicitly.
        $this->ki->verwerfen();
        $this->log->ereignis('worker_ende');
    }

    /**
     * One round. Returns the seconds to wait before the next.
     */
    public function runde(): float
    {
        try {
            $antwort = $this->api->json('POST', '/heartbeat', [
                'protokoll_version' => WorkerProtokoll::VERSION,
                'version' => $this->version,
                'faehigkeiten' => $this->faehigkeiten,
                'config_version' => $this->ki->version(),
            ]);
            if (is_int($antwort['server_zeit'] ?? null)) {
                $this->api->serverZeit($antwort['server_zeit'], ($this->uhr)());
            }
            if (is_array($antwort['config'] ?? null)) {
                $this->ki->uebernehmen($antwort['config'], $this->identitaet->schluesselpaar);
                $this->log->ereignis('konfiguration', ['version' => $this->ki->version(), 'ki_profile' => $this->ki->anzahl()]);
            }
            $status = is_string($antwort['status'] ?? null) ? $antwort['status'] : '';
            if ($status !== $this->letzterStatus) {
                $this->log->ereignis('status', ['status' => $status]);
                $this->letzterStatus = $status;
            }
            $this->fehlerInFolge = 0;
            $poll = is_int($antwort['poll_sekunden'] ?? null) ? max(10, min(600, $antwort['poll_sekunden'])) : 60;

            if ($status !== 'aktiv') {
                // Paired, waiting for the admin to confirm the fingerprint.
                $this->ki->verwerfen();

                return $poll;
            }
            if (!is_int($antwort['wartend'] ?? null) || $antwort['wartend'] < 1 || $this->abbruch->angefordert()) {
                return $poll;
            }

            $jobs = $this->api->json('POST', '/jobs/claim', ['max' => 1])['jobs'] ?? [];
            $bearbeitet = 0;
            foreach (is_array($jobs) ? $jobs : [] as $job) {
                if (is_array($job)) {
                    $this->verarbeiten($job);
                    ++$bearbeitet;
                }
            }

            return $bearbeitet > 0 ? 0 : $poll;
        } catch (Abgebrochen) {
            return 0;
        } catch (ApiFehler $e) {
            $this->log->ereignis('webhoster_fehler', ['status' => $e->status, 'fehler' => $e->fehler]);
            if ($e->fehler === 'nicht_authentifiziert') {
                // Unpaired in the admin, or another key: nothing to keep.
                $this->ki->verwerfen();
            }

            return $this->zurueckweichen();
        } catch (HttpFehler) {
            $this->log->ereignis('webhoster_nicht_erreichbar');

            return $this->zurueckweichen();
        }
    }

    public function fehlerInFolge(): int
    {
        return $this->fehlerInFolge;
    }

    /** The pause after the n-th error in a row: 5 s doubling to 5 min, ±20 %. */
    public static function backoff(int $fehlerInFolge, float $zufall = 0.5): float
    {
        $basis = min(self::BACKOFF_MAX_SEKUNDEN, self::BACKOFF_START_SEKUNDEN * 2 ** max(0, min(16, $fehlerInFolge - 1)));

        return $basis * (0.8 + 0.4 * max(0.0, min(1.0, $zufall)));
    }

    private function zurueckweichen(): float
    {
        ++$this->fehlerInFolge;

        return self::backoff($this->fehlerInFolge, mt_rand() / mt_getrandmax());
    }

    /**
     * @param array<string, mixed> $job one entry of the claim answer
     */
    private function verarbeiten(array $job): void
    {
        $lauf = JobLauf::ausClaim($this->api, $this->identitaet, $this->abbruch, $this->uhr, $job);
        $start = microtime(true);
        $this->log->ereignis('job_start', ['job' => $lauf->id, 'typ' => $lauf->typ, 'versuch' => $lauf->versuch]);
        $schritt = $this->schritte[$lauf->typ] ?? null;

        try {
            if ($schritt === null) {
                throw new JobFehler('JobTypNichtUnterstuetzt', true);
            }
            $this->arbeit->mitJob(static fn(string $verzeichnis) => $schritt->ausfuehren($lauf, $verzeichnis));
            $this->api->json('POST', '/jobs/' . $lauf->id . '/complete', ['lock' => $lauf->lock], abbrechbar: false);
            $this->log->ereignis('job_fertig', ['job' => $lauf->id, 'typ' => $lauf->typ, 'sekunden' => round(microtime(true) - $start, 1)]);
        } catch (Abgebrochen) {
            $this->zurueckgeben($lauf, 'WorkerBeendet', false);
        } catch (JobFehler | WerkzeugFehler $e) {
            $this->zurueckgeben($lauf, $e->fehlercode, $e->endgueltig);
        } catch (ApiFehler $e) {
            if ($e->jobVerloren()) {
                // Lock expired or the job went elsewhere: nothing to give back.
                $this->log->ereignis('job_verloren', ['job' => $lauf->id, 'fehler' => $e->fehler]);

                return;
            }
            $this->zurueckgeben($lauf, 'WorkerApiFehler', false);
        } catch (\Throwable $e) {
            // The class name only - a message could quote receipt data.
            $this->zurueckgeben($lauf, 'WorkerFehler:' . (new \ReflectionClass($e))->getShortName(), false);
        }
    }

    private function zurueckgeben(JobLauf $lauf, string $fehler, bool $endgueltig): void
    {
        $this->log->ereignis('job_fehler', ['job' => $lauf->id, 'typ' => $lauf->typ, 'fehler' => $fehler, 'endgueltig' => $endgueltig]);
        try {
            $this->api->json(
                'POST',
                '/jobs/' . $lauf->id . '/fail',
                ['lock' => $lauf->lock, 'fehler' => $fehler, 'endgueltig' => $endgueltig],
                abbrechbar: false,
                zeitlimit: $this->abbruch->angefordert() ? self::RUECKGABE_ZEITLIMIT : null,
            );
        } catch (\Throwable $e) {
            // The lock runs out after 30 min; the web host offers it again.
            $this->log->ereignis('rueckgabe_fehlgeschlagen', ['job' => $lauf->id, 'fehler' => $e instanceof ApiFehler ? $e->fehler : (new \ReflectionClass($e))->getShortName()]);
        }
    }
}
