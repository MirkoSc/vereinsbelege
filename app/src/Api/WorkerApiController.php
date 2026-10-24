<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Http\Request;
use App\Http\Response;
use App\Repository\JobRepository;
use App\Repository\WorkerRepository;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;

/**
 * The web host's API for the optional worker, `/api/worker/*`
 * (docs/spec/07-worker.md section 5, issue #51/M7b-1). The worker only ever
 * calls out (polling) - the web host never connects to it.
 *
 * `pair` is the one route without a signature: the pairing code is its
 * credential (App\Service\Worker\WorkerKopplung). Everything else arrives
 * here only after App\Service\Worker\WorkerAuth verified the request
 * (app/src/routes.php wraps it), with the authenticated worker as argument.
 *
 * Blobs, context and artifact upload (issue #52) and the routing that puts
 * jobs on `executor = worker` (issue #53) build on this. Nothing here sends
 * or stores business data: job rows are ids and counters, error codes are
 * identifiers.
 */
final readonly class WorkerApiController
{
    /** How long a claim holds a job (section 5: "z. B. 30 min"). */
    public const int SPERRE_SEKUNDEN = 1800;

    /** At most this many jobs per claim. */
    public const int MAX_CLAIM = 5;

    /** Like App\Service\Job\JobRunner: a job that failed this often stops. */
    public const int MAX_VERSUCHE = 3;

    /** Section 5: polling interval without work. */
    public const int POLL_SEKUNDEN = 60;

    /** The JSON bodies of these routes are small; anything larger is refused unread. */
    private const int MAX_JSON_BYTES = 65536;

    public function __construct(
        private WorkerKopplung $kopplung,
        private WorkerRepository $workers,
        private JobRepository $jobs,
    ) {
    }

    public function pair(Request $request): Response
    {
        $daten = self::json($request);
        if ($daten === null) {
            return self::fehler(400, 'body_ungueltig', 'Erwartet wird ein JSON-Objekt.');
        }

        $ergebnis = $this->kopplung->koppeln(
            code: self::text($daten, 'code'),
            publicKeyB64: self::text($daten, 'public_key'),
            name: self::text($daten, 'name'),
            protokollVersion: self::zahl($daten, 'protokoll_version'),
            appVersion: self::text($daten, 'version'),
            ip: $request->ip,
            now: new \DateTimeImmutable(),
        );
        if (!$ergebnis->istGekoppelt()) {
            return match ($ergebnis->fehler) {
                'protokoll' => self::protokollFehler(),
                'zu_viele_fehlversuche' => self::fehler(429, 'zu_viele_fehlversuche', 'Zu viele falsche Codes – bitte später erneut versuchen.'),
                'schluessel_ungueltig' => self::fehler(422, 'schluessel_ungueltig', 'Der öffentliche Schlüssel ist ungültig.'),
                'bereits_gekoppelt' => self::fehler(409, 'bereits_gekoppelt', 'Es ist bereits ein Worker gekoppelt.'),
                default => self::fehler(403, 'code_ungueltig', 'Der Kopplungscode ist ungültig oder abgelaufen.'),
            };
        }

        return Response::json([
            'worker_id' => $ergebnis->workerId,
            'schluessel' => base64_encode((string) $ergebnis->schluessel),
            'fingerabdruck' => WorkerProtokoll::fingerabdruckAnzeige((string) $ergebnis->fingerabdruck),
            'status' => WorkerStatus::Gekoppelt->value,
            'protokoll_version' => WorkerProtokoll::VERSION,
        ]);
    }

    public function heartbeat(Request $request, Worker $worker): Response
    {
        $daten = self::json($request);
        if ($daten === null) {
            return self::fehler(400, 'body_ungueltig', 'Erwartet wird ein JSON-Objekt.');
        }

        $protokoll = self::zahl($daten, 'protokoll_version');
        $faehigkeiten = [];
        $liste = $daten['faehigkeiten'] ?? [];
        foreach (is_array($liste) ? $liste : [] as $wert) {
            $faehigkeit = is_string($wert) ? WorkerFaehigkeit::tryFrom($wert) : null;
            if ($faehigkeit !== null && !in_array($faehigkeit, $faehigkeiten, true)) {
                $faehigkeiten[] = $faehigkeit;
            }
        }
        $version = self::text($daten, 'version');
        $now = new \DateTimeImmutable();

        // Stored even when incompatible: the admin page shows "Worker-Update
        // nötig" from it, and claims refuse it (section 5).
        $this->workers->heartbeat(
            $worker->id,
            max(0, min($protokoll, 65535)),
            preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $version) === 1 ? $version : '',
            $faehigkeiten,
            $now,
        );
        if (!WorkerProtokoll::unterstuetzt($protokoll)) {
            return self::protokollFehler();
        }

        $aktiv = $worker->status === WorkerStatus::Aktiv;
        $typen = WorkerFaehigkeit::jobTypenFuer($faehigkeiten);
        $bekannt = self::zahl($daten, 'config_version');

        return Response::json([
            'status' => $worker->status->value,
            'server_zeit' => $now->getTimestamp(),
            'poll_sekunden' => self::POLL_SEKUNDEN,
            'wartend' => $aktiv ? $this->jobs->zaehleOffen(JobExecutor::Worker, $typen) : 0,
            'config' => $aktiv && $worker->configSealed !== null && $worker->configVersion > $bekannt
                ? ['version' => $worker->configVersion, 'versiegelt' => base64_encode($worker->configSealed)]
                : null,
        ]);
    }

    public function claim(Request $request, Worker $worker): Response
    {
        if (($sperre = self::nurAktiv($worker)) !== null) {
            return $sperre;
        }
        $daten = self::json($request);
        if ($daten === null) {
            return self::fehler(400, 'body_ungueltig', 'Erwartet wird ein JSON-Objekt.');
        }

        $max = max(1, min(self::zahl($daten, 'max', 1), self::MAX_CLAIM));
        $typen = $worker->jobTypen();
        $now = new \DateTimeImmutable();
        $jobs = [];
        // A few more rounds than $max: a claimed job that is out of attempts
        // is closed here instead of handed out, and must not stall the rest.
        for ($runde = 0; $runde < $max * 2 && count($jobs) < $max && $typen !== []; ++$runde) {
            $lock = $worker->lockPraefix() . bin2hex(random_bytes(8));
            $job = $this->jobs->claim(JobExecutor::Worker, $lock, self::SPERRE_SEKUNDEN, now: $now, typen: $typen);
            if ($job === null) {
                break;
            }
            if ($job->attempts > self::MAX_VERSUCHE) {
                $this->jobs->fail($job->id, 'WorkerVersucheErschoepft', $now, $lock);

                continue;
            }
            $jobs[] = self::jobDaten($job, $lock);
        }

        return Response::json(['jobs' => $jobs]);
    }

    /**
     * @param array<string, string> $params
     */
    public function complete(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job, $lock] = $geprueft;

        return $this->jobs->abschliessen($job->id, $lock)
            ? Response::json(['status' => JobStatus::Fertig->value])
            : self::sperreVerloren();
    }

    /**
     * @param array<string, string> $params
     */
    public function fail(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job, $lock, $daten] = $geprueft;

        // An identifier, never a message: `last_error` is plaintext and a
        // message could quote receipt data (section 5: "ohne Klartext").
        $code = self::text($daten, 'fehler');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:\\\\-]{0,119}$/', $code) !== 1) {
            $code = 'WorkerFehler';
        }
        $endgueltig = ($daten['endgueltig'] ?? false) === true || $job->attempts >= self::MAX_VERSUCHE;

        if ($endgueltig) {
            return $this->jobs->fail($job->id, $code, null, $lock)
                ? Response::json(['status' => JobStatus::Fehler->value])
                : self::sperreVerloren();
        }

        return $this->jobs->zurueckgebenMitFehler($job->id, $lock, $code)
            ? Response::json(['status' => JobStatus::Offen->value])
            : self::sperreVerloren();
    }

    /**
     * The job of a complete/fail call, if this worker holds its lock.
     *
     * @param array<string, string> $params
     * @return array{Job, string, array<string, mixed>}|Response
     */
    private function eigenerJob(Request $request, array $params, Worker $worker): array|Response
    {
        if (($sperre = self::nurAktiv($worker)) !== null) {
            return $sperre;
        }
        $daten = self::json($request);
        if ($daten === null) {
            return self::fehler(400, 'body_ungueltig', 'Erwartet wird ein JSON-Objekt.');
        }

        $lock = self::text($daten, 'lock');
        $job = $this->jobs->find((int) ($params['id'] ?? 0));
        // One answer for "no such job", "not a worker job" and "another
        // worker's job": a worker learns nothing about jobs it does not hold.
        if ($job === null || $job->executor !== JobExecutor::Worker || !str_starts_with($job->lockedBy ?? '', $worker->lockPraefix())) {
            return self::fehler(404, 'job_unbekannt', 'Kein Job dieses Workers.');
        }
        if ($job->status !== JobStatus::Laeuft || !hash_equals((string) $job->lockedBy, $lock)) {
            return self::sperreVerloren();
        }

        return [$job, $lock, $daten];
    }

    private static function nurAktiv(Worker $worker): ?Response
    {
        if ($worker->status !== WorkerStatus::Aktiv) {
            return self::fehler(403, 'nicht_bestaetigt', 'Der Worker wartet auf die Bestätigung im Admin.');
        }
        if (!WorkerProtokoll::unterstuetzt($worker->protokollVersion)) {
            return self::protokollFehler();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function jobDaten(Job $job, string $lock): array
    {
        return [
            'id' => $job->id,
            'typ' => $job->typ,
            'ref_type' => $job->refType,
            'ref_id' => $job->refId,
            'step' => $job->step,
            'versuch' => $job->attempts,
            'lock' => $lock,
            'gesperrt_bis' => $job->lockedUntil?->getTimestamp(),
        ];
    }

    /**
     * The verified raw body as an object - the very bytes the signature
     * covers, not a second reading of them.
     *
     * @return ?array<string, mixed>
     */
    private static function json(Request $request): ?array
    {
        $body = $request->body();
        if ($body === '') {
            return [];
        }
        if (strlen($body) > self::MAX_JSON_BYTES) {
            return null;
        }
        $daten = json_decode($body, true);

        return is_array($daten) && !array_is_list($daten) || $daten === [] ? $daten : null;
    }

    /**
     * @param array<string, mixed> $daten
     */
    private static function text(array $daten, string $feld): string
    {
        $wert = $daten[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    /**
     * @param array<string, mixed> $daten
     */
    private static function zahl(array $daten, string $feld, int $standard = 0): int
    {
        $wert = $daten[$feld] ?? $standard;

        return is_int($wert) ? $wert : $standard;
    }

    private static function protokollFehler(): Response
    {
        return Response::json([
            'fehler' => 'protokoll',
            'meldung' => 'Worker-Update nötig: Protokollversion wird nicht unterstützt.',
            'unterstuetzt' => WorkerProtokoll::UNTERSTUETZT,
        ], 409);
    }

    private static function sperreVerloren(): Response
    {
        return self::fehler(409, 'sperre_verloren', 'Der Job ist nicht mehr von diesem Worker gesperrt.');
    }

    private static function fehler(int $status, string $fehler, string $meldung): Response
    {
        return Response::json(['fehler' => $fehler, 'meldung' => $meldung], $status);
    }
}
