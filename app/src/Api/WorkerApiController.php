<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Domain\Category;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\StreamResponse;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerGrantRepository;
use App\Repository\WorkerRepository;
use App\Service\Storage\BlobService;
use App\Service\Worker\MailEingangSpeicher;
use App\Service\Worker\WorkerArtefaktFehler;
use App\Service\Worker\WorkerArtefakte;
use App\Service\Worker\WorkerFallback;
use App\Service\Worker\WorkerFreigaben;
use App\Service\Worker\WorkerKonfiguration;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Service\Worker\WorkerUebernahme;

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
 * Blobs, context and artifact upload (issue #52/M7b-2): the worker gets
 * ciphertext plus data keys sealed to ITS key (worker_grant) and only for a
 * document it holds a job on right now; it hands back ciphertext whose data
 * keys it sealed to VK_pub. The routing that puts jobs on `executor =
 * worker` is issue #53. Nothing here sends or stores business data in the
 * clear: job rows are ids and counters, error codes are identifiers.
 */
final readonly class WorkerApiController
{
    /** How long a claim holds a job (section 5: "z. B. 30 min"). */
    public const int SPERRE_SEKUNDEN = 1800;

    /** At most this many jobs per claim. */
    public const int MAX_CLAIM = 5;

    /** Like App\Service\Job\JobRunner: a job that failed this often stops. */
    public const int MAX_VERSUCHE = 3;

    /**
     * Releases on shutdown that do not use up an attempt (issue #248) - after
     * that a release counts like a failed attempt, so a job that is cut off
     * again and again (a restart loop) still ends and falls back.
     */
    public const int MAX_FREIGABEN = 3;

    /** Section 5: polling interval without work. */
    public const int POLL_SEKUNDEN = 60;

    /** The JSON bodies of these routes are small; anything larger is refused unread. */
    private const int MAX_JSON_BYTES = 65536;

    /** Largest body of one artifact chunk: the upload chunk size plus some slack. */
    private const int MAX_CHUNK_BYTES = 2 * 1024 * 1024 + 4096;

    public function __construct(
        private WorkerKopplung $kopplung,
        private WorkerRepository $workers,
        private JobRepository $jobs,
        private WorkerGrantRepository $grants,
        private WorkerFreigaben $freigaben,
        private WorkerArtefakte $artefakte,
        private WorkerKonfiguration $konfiguration,
        private BlobService $blobs,
        private VaultRepository $vaults,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        // Issue #53: module switch, AI key release, fallback after failure.
        private WorkerRouting $routing,
        private WorkerFallback $fallback,
        // Issue #54: which granted blob is which original, for bin/worker.php.
        private DocumentRepository $documents,
        // Issue #87: the mail intake's status report on heartbeat.
        private ?MailEingangSpeicher $mailEingang = null,
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
        if ($aktiv) {
            // AI provider profiles incl. API keys, sealed to W_pub - anew
            // only when something changed (07 section 3 "KI-Zugangsdaten").
            // The keys only with the module on and the `ai` capability just
            // reported (review of #52) - else an empty list replaces them.
            // The IMAP access of the mail intake likewise, with `mail`
            // (issue #87, E-54).
            $worker = $this->konfiguration->aktualisieren(
                $worker,
                $this->routing->kiSchluesselErlaubt($faehigkeiten),
                $this->routing->mailZugangErlaubt($faehigkeiten),
            );
            if (is_array($daten['mail_status'] ?? null)) {
                // Time, error code and counters of its last mailbox run,
                // for the admin page - nothing else is kept.
                $this->mailEingang?->statusMerken($daten['mail_status']);
            }
        }
        $typen = WorkerFaehigkeit::jobTypenFuer($faehigkeiten);
        $bekannt = self::zahl($daten, 'config_version');

        return Response::json([
            'status' => $worker->status->value,
            'server_zeit' => $now->getTimestamp(),
            'poll_sekunden' => self::POLL_SEKUNDEN,
            'wartend' => $aktiv && $this->routing->modulAktiv() ? $this->jobs->zaehleBeanspruchbar(JobExecutor::Worker, $typen, $now, WorkerRouting::VORGAENGER) : 0,
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

        // Module switched off (issue #53): nothing to claim - what is
        // still queued for the worker goes back with the next fallback run.
        if (!$this->routing->modulAktiv()) {
            return Response::json(['jobs' => []]);
        }

        $max = max(1, min(self::zahl($daten, 'max', 1), self::MAX_CLAIM));
        $typen = $worker->jobTypen();
        $now = new \DateTimeImmutable();
        $jobs = [];
        // A few more rounds than $max: a claimed job that is out of attempts
        // is closed here instead of handed out, and must not stall the rest.
        for ($runde = 0; $runde < $max * 2 && count($jobs) < $max && $typen !== []; ++$runde) {
            $lock = $worker->lockPraefix() . bin2hex(random_bytes(8));
            $job = $this->jobs->claim(JobExecutor::Worker, $lock, self::SPERRE_SEKUNDEN, now: $now, typen: $typen, warteAuf: WorkerRouting::VORGAENGER);
            if ($job === null) {
                break;
            }
            if ($job->attempts > self::MAX_VERSUCHE) {
                $this->jobs->fail($job->id, 'WorkerVersucheErschoepft', $now, $lock);
                $this->freigaben->nachJob($job);
                $this->fallback->nachFehlschlag($job, $now);

                continue;
            }
            // An earlier attempt that died (lock ran out) may have
            // committed part of its results: this attempt starts clean
            // (issue #248).
            // Always, not only from the second attempt on: a release whose
            // cleanup failed leaves the attempt number unchanged (review of
            // #248). Cheap when there is nothing.
            $this->artefakte->abgebrochenenVersuchVerwerfen($job);
            $jobs[] = self::jobDaten($job, $lock);
        }

        // Unpaired while this request was under way: entkoppeln() may have
        // released the worker's jobs before the claims above, so give back
        // what was just taken.
        if ($jobs !== [] && $this->workers->find($worker->id)?->status !== WorkerStatus::Aktiv) {
            $this->jobs->freigebenFuerPraefix($worker->lockPraefix(), $now);

            return self::fehler(401, 'nicht_authentifiziert', 'Anfrage nicht authentifiziert.');
        }

        return Response::json(['jobs' => $jobs]);
    }

    /**
     * Extends the lock of a job the worker is still working on (a long OCR
     * or AI run), by SPERRE_SEKUNDEN from now. Only the holder may.
     *
     * @param array<string, string> $params
     */
    public function extend(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job, $lock] = $geprueft;
        if ($job->status !== JobStatus::Laeuft) {
            return self::sperreVerloren();
        }

        $now = new \DateTimeImmutable();

        return $this->jobs->heartbeat($job->id, $lock, self::SPERRE_SEKUNDEN, $now)
            ? Response::json(['gesperrt_bis' => $now->getTimestamp() + self::SPERRE_SEKUNDEN])
            : self::sperreVerloren();
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
        // A repeat of a complete whose answer got lost.
        if ($job->status === JobStatus::Fertig) {
            return Response::json(['status' => JobStatus::Fertig->value]);
        }

        if (!$this->jobs->abschliessen($job->id, $lock)) {
            return self::sperreVerloren();
        }
        $this->freigaben->nachJob($job);
        // Checking the results needs the vault: a session job takes them
        // over (issue #53, App\Service\Worker\WorkerUebernahme).
        WorkerUebernahme::einreihen($this->jobs, $job, new \DateTimeImmutable());

        return Response::json(['status' => JobStatus::Fertig->value]);
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
        if ($job->status !== JobStatus::Laeuft) {
            return self::sperreVerloren();
        }

        // An identifier, never a message: `last_error` is plaintext and a
        // message could quote receipt data (section 5: "ohne Klartext").
        $code = self::text($daten, 'fehler');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:\\\\-]{0,119}$/', $code) !== 1) {
            $code = 'WorkerFehler';
        }
        $endgueltig = ($daten['endgueltig'] ?? false) === true || $job->attempts >= self::MAX_VERSUCHE;

        if ($endgueltig) {
            if (!$this->jobs->fail($job->id, $code, null, $lock)) {
                return self::sperreVerloren();
            }
            $this->freigaben->nachJob($job);
            $this->fallback->nachFehlschlag($job, new \DateTimeImmutable());

            return Response::json(['status' => JobStatus::Fehler->value]);
        }

        if (!$this->jobs->zurueckgebenMitFehler($job->id, $lock, $code)) {
            return self::sperreVerloren();
        }
        // The grants stay for the next attempt; this attempt's uploads and
        // what it already committed go (issue #248) - the next attempt
        // delivers a complete run of its own.
        $this->artefakte->abgebrochenenVersuchVerwerfen($job);
        $this->freigaben->nachJob($job);

        return Response::json(['status' => JobStatus::Offen->value]);
    }

    /**
     * `POST /api/worker/jobs/{id}/release` `{lock}` (issue #248): the worker
     * was told to stop (SIGTERM) and gives the job back WITHOUT using up an
     * attempt - unlike `fail`, the cut-off run says nothing about the job.
     * Up to MAX_FREIGABEN times per job; later releases keep the attempt.
     * What the attempt uploaded or committed is dropped; the grants stay.
     * Idempotent: a repeat by the same holder (its answer got lost) is
     * answered 200 again as long as nobody claimed the job since.
     *
     * @param array<string, string> $params
     */
    public function release(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job, $lock] = $geprueft;
        // A repeat: the release kept `locked_by`, so the job is still "ours".
        if ($job->status === JobStatus::Offen) {
            return Response::json(['status' => JobStatus::Offen->value]);
        }
        if ($job->status !== JobStatus::Laeuft) {
            return self::sperreVerloren();
        }
        // Clean up while the job is still held - before it becomes
        // claimable again; the next claim cleans once more anyway.
        $this->artefakte->abgebrochenenVersuchVerwerfen($job);
        $freigaben = is_int($job->state['freigaben'] ?? null) ? $job->state['freigaben'] : 0;
        if (!$this->jobs->zurueckgebenOhneVersuch($job->id, $lock, ['freigaben' => $freigaben + 1] + $job->state, $freigaben < self::MAX_FREIGABEN)) {
            return self::sperreVerloren();
        }
        $this->freigaben->nachJob($job);

        return Response::json(['status' => JobStatus::Offen->value]);
    }

    /**
     * `GET /api/worker/blobs/{id}`: the ciphertext of a blob, with its data
     * key sealed to this worker (header `X-Worker-Dek`) and the secretstream
     * header (`X-Blob-Header`). Only with a grant for this worker AND a
     * running claim of this worker on a job of the blob's document - one
     * answer (403) for every other case, so nothing is learnt about blobs
     * that are not one's own (07 section 5, Pflicht-Test).
     *
     * @param array<string, string> $params
     */
    public function blob(Request $request, array $params, Worker $worker): ResponseInterface
    {
        if (($sperre = self::nurAktiv($worker)) !== null) {
            return $sperre;
        }
        $blobId = (int) ($params['id'] ?? 0);
        $grant = $this->grants->grantFuerGehaltenenJob($worker->id, $blobId, $worker->lockPraefix(), new \DateTimeImmutable());
        $blob = $grant === null ? null : $this->blobs->find($blobId);
        if ($grant === null || $blob === null || !$blob->isComplete()) {
            return self::fehler(403, 'kein_grant', 'Kein Zugriff auf diese Datei.');
        }

        return new StreamResponse($this->blobs->readCipher($blob), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
            'X-Worker-Dek' => base64_encode($grant['sealed_dek']),
            'X-Blob-Header' => base64_encode($blob->header),
            'X-Blob-Groesse' => (string) $blob->size,
        ]);
    }

    /**
     * `GET /api/worker/jobs/{id}/context`: what the worker needs for a job
     * it holds - the blobs it may fetch, the AI context sealed to it, VK_pub
     * to seal its results to, and non-sensitive master data for the prompt
     * (categories with their AI hint, cost centers). No query string, no
     * body: a running, unexpired claim of this worker is the credential.
     *
     * @param array<string, string> $params
     */
    public function context(Request $request, array $params, Worker $worker): Response
    {
        if (($sperre = self::nurAktiv($worker)) !== null) {
            return $sperre;
        }
        $now = new \DateTimeImmutable();
        $job = $this->jobs->find((int) ($params['id'] ?? 0));
        if ($job === null || !self::haeltJob($job, $worker, $now)) {
            return self::fehler(404, 'job_unbekannt', 'Kein Job dieses Workers.');
        }
        $tresor = $this->vaults->current();
        if ($tresor === null) {
            return self::fehler(409, 'kein_tresor', 'Der Tresor ist nicht eingerichtet.');
        }

        $dokument = null;
        if ($job->refType === 'document' && $job->refId !== null) {
            $kontext = $this->grants->kontextFuer($worker->id, $job->refId, $now);
            $blobs = $this->grants->blobIdsFuer($worker->id, $job->refId, $now);
            $gewaehrt = static fn(?int $id): ?int => $id !== null && in_array($id, $blobs, true) ? $id : null;
            $document = $this->documents->find($job->refId);
            $dokument = [
                'id' => $job->refId,
                'blobs' => $blobs,
                'kontext' => $kontext === null ? null : base64_encode($kontext),
                // Ids only (issue #54): the worker numbers its `text` and
                // `pdfa` results by the original's place, and prefers the
                // processed scan of an image page. Content and type of each
                // blob it learns only by opening its grant.
                'originale' => $document?->originalBlobIds ?? [],
                'verarbeitet' => array_map($gewaehrt, $document?->processedBlobIds ?? []),
                'pdf' => $gewaehrt($document?->pdfBlobId),
            ];
        }

        return Response::json([
            'job' => self::jobDaten($job, (string) $job->lockedBy),
            'dokument' => $dokument,
            'tresor' => ['version' => $tresor->version, 'public_key' => base64_encode($tresor->publicKey())],
            'artefakte' => array_map(static fn($k): string => $k->value, WorkerArtefakte::erlaubteKinds($job->typ)),
            'stammdaten' => [
                'kategorien' => array_values(array_map(static fn(Category $k): array => [
                    'id' => $k->id,
                    'name' => $k->name,
                    'richtung' => $k->direction->value,
                    'ki_hinweis' => $k->aiHint,
                ], array_filter($this->kategorien->all(), static fn(Category $k): bool => $k->active))),
                'kostenstellen' => self::kostenstellenListe($this->kostenstellen->active()),
            ],
        ]);
    }

    /**
     * `POST /api/worker/jobs/{id}/artifacts`: announces one result (JSON
     * `{lock, versuch, kind, seq, dek_sealed, header?, groesse, sha256}`,
     * binary values Base64, `sha256` hex of the ciphertext).
     *
     * @param array<string, string> $params
     */
    public function artefaktAnmelden(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job, , $daten] = $geprueft;
        if (!self::haeltJob($job, $worker, new \DateTimeImmutable())) {
            return self::sperreVerloren();
        }
        $header = self::text($daten, 'header');

        try {
            $antwort = $this->artefakte->anmelden(
                $worker,
                $job,
                self::zahl($daten, 'versuch', -1),
                self::text($daten, 'kind'),
                self::zahl($daten, 'seq', -1),
                (string) base64_decode(self::text($daten, 'dek_sealed'), true),
                $header === '' ? null : (string) base64_decode($header, true),
                self::zahl($daten, 'groesse'),
                self::text($daten, 'sha256'),
                new \DateTimeImmutable(),
            );
        } catch (WorkerArtefaktFehler $e) {
            return self::artefaktFehler($e);
        }

        return Response::json($antwort, ($antwort['status'] ?? '') === 'offen' ? 201 : 200);
    }

    /**
     * `POST /api/worker/jobs/{id}/artifacts/{artefakt}/chunks/{index}`: one
     * piece of ciphertext as the raw body. No lock in the request (the body
     * is the ciphertext): the upload is bound to worker, job and attempt
     * from its announcement, and the job must still be this worker's
     * running claim.
     *
     * @param array<string, string> $params
     */
    public function artefaktChunk(Request $request, array $params, Worker $worker): Response
    {
        $job = $this->gehaltenerJob($params, $worker);
        if ($job instanceof Response) {
            return $job;
        }
        $body = $request->body();
        if (strlen($body) > self::MAX_CHUNK_BYTES) {
            return self::fehler(413, 'zu_gross', 'Der Teil ist zu groß.');
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a memory stream.');
        }
        fwrite($stream, $body);
        rewind($stream);

        try {
            return Response::json($this->artefakte->chunk($worker, $job, (int) ($params['artefakt'] ?? 0), (int) ($params['index'] ?? -1), $stream));
        } catch (WorkerArtefaktFehler $e) {
            return self::artefaktFehler($e);
        } finally {
            fclose($stream);
        }
    }

    /**
     * `POST /api/worker/jobs/{id}/artifacts/{artefakt}/commit` `{lock}`.
     *
     * @param array<string, string> $params
     */
    public function artefaktFestschreiben(Request $request, array $params, Worker $worker): Response
    {
        $geprueft = $this->eigenerJob($request, $params, $worker);
        if ($geprueft instanceof Response) {
            return $geprueft;
        }
        [$job] = $geprueft;
        if (!self::haeltJob($job, $worker, new \DateTimeImmutable())) {
            return self::sperreVerloren();
        }

        try {
            return Response::json($this->artefakte->festschreiben($worker, $job, (int) ($params['artefakt'] ?? 0)));
        } catch (WorkerArtefaktFehler $e) {
            return self::artefaktFehler($e);
        }
    }

    /**
     * The job of a chunk upload: a running, unexpired claim of this worker.
     *
     * @param array<string, string> $params
     */
    private function gehaltenerJob(array $params, Worker $worker): Job|Response
    {
        if (($sperre = self::nurAktiv($worker)) !== null) {
            return $sperre;
        }
        $job = $this->jobs->find((int) ($params['id'] ?? 0));
        if ($job === null || !self::haeltJob($job, $worker, new \DateTimeImmutable())) {
            return self::fehler(404, 'job_unbekannt', 'Kein Job dieses Workers.');
        }

        return $job;
    }

    /** A running claim of this worker whose lock has not run out. */
    private static function haeltJob(Job $job, Worker $worker, \DateTimeImmutable $now): bool
    {
        return $job->executor === JobExecutor::Worker
            && $job->status === JobStatus::Laeuft
            && str_starts_with($job->lockedBy ?? '', $worker->lockPraefix())
            && $job->lockedUntil !== null && $job->lockedUntil > $now;
    }

    /**
     * @param array<int, string> $kostenstellen
     *
     * @return list<array{id: int, name: string}>
     */
    private static function kostenstellenListe(array $kostenstellen): array
    {
        $liste = [];
        foreach ($kostenstellen as $id => $name) {
            $liste[] = ['id' => (int) $id, 'name' => (string) $name];
        }

        return $liste;
    }

    private static function artefaktFehler(WorkerArtefaktFehler $e): Response
    {
        return self::fehler($e->status, $e->fehler, 'Das Ergebnis wurde nicht angenommen.');
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
        // The status is the caller's business (complete accepts a repeat on
        // a finished job); the lock must be exactly this claim's.
        if (!hash_equals((string) $job->lockedBy, $lock)) {
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
