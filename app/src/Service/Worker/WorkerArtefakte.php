<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\ArtifactKind;
use App\Domain\Job;
use App\Domain\JobStatus;
use App\Domain\Worker;
use App\Domain\WorkerUpload;
use App\Repository\DocumentArtifactRepository;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerUploadRepository;
use App\Service\Storage\BlobException;
use App\Service\Storage\BlobService;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;

/**
 * The worker's result upload, `POST /api/worker/jobs/{id}/artifacts...`
 * (docs/spec/07-worker.md sections 3 and 5, issue #52/M7b-2, E-39).
 *
 * The worker encrypts every result under a fresh data key and seals that
 * key to VK_pub (App\Service\Worker\WorkerKrypto) before anything leaves
 * it; the web host only ever receives ciphertext and can check its shape,
 * never its content. Three steps, each a short request:
 *
 *   1. anmelden(): kind, seq, the job attempt, the sealed data key, the
 *      ciphertext length, for a blob also the secretstream header and the
 *      SHA-256 of the ciphertext (not for `data_enc`: its AAD contains the
 *      row id this step hands out, so its checksum cannot exist yet). Reserves
 *      the `document_artifact` row (invisible to readers until step 3) and
 *      answers with its id - the AAD of `data_enc` needs it.
 *   2. chunk(): the ciphertext in pieces of UploadService::CHUNK_BYTES, into
 *      shared/var/tmp (ciphertext only).
 *   3. festschreiben(): checks length and checksum, stores the blob or
 *      `data_enc` and makes the row visible in one statement.
 *
 * Only for a job the worker holds right now (lock and attempt), only kinds
 * the job type produces, bounded sizes and a bounded number of open uploads.
 * Idempotent: an artifact already committed for (document, kind, job, seq)
 * is reported as `vorhanden` instead of stored twice.
 */
final readonly class WorkerArtefakte
{
    /** Largest blob ciphertext (a PDF/A of a long scan). */
    public const int BLOB_MAX_BYTES = 64 * 1024 * 1024;

    /** Largest `data_enc` (OCR text, AI result). */
    public const int DATEN_MAX_BYTES = 4 * 1024 * 1024;

    /** Open (announced, not committed) uploads per worker at once. */
    public const int MAX_OFFENE = 10;

    /** Highest seq (page number) of one artifact kind. */
    public const int MAX_SEQ = 9999;

    /** The cron drops announcements older than this. */
    public const int OFFEN_HOECHSTENS_SEKUNDEN = 86400;

    public function __construct(
        private DocumentArtifactRepository $artefakte,
        private WorkerUploadRepository $uploads,
        private UploadService $ablage,
        private BlobService $blobs,
        private VaultRepository $vaults,
        private JobRepository $jobs,
        private SettingRepository $settings,
    ) {
    }

    /**
     * What a worker job of this type may deliver. Everything else is
     * refused - a worker cannot plant, say, a `vorgabe` or `lieferant`.
     *
     * @return list<ArtifactKind>
     */
    public static function erlaubteKinds(string $jobTyp): array
    {
        return match ($jobTyp) {
            'render_pages' => [ArtifactKind::PageImage],
            'ocr' => [ArtifactKind::Pdfa, ArtifactKind::Text],
            'image_cleanup' => [ArtifactKind::Pdfa],
            'ai_extract' => [ArtifactKind::Extraction],
            default => [],
        };
    }

    public static function art(ArtifactKind $kind): string
    {
        return in_array($kind, [ArtifactKind::PageImage, ArtifactKind::Pdfa], true) ? WorkerUpload::ART_BLOB : WorkerUpload::ART_DATEN;
    }

    /**
     * Step 1. $job must be one the worker holds under the lock it sent
     * (the controller checks that).
     *
     * @return array<string, mixed> the answer for the worker
     *
     * @throws WorkerArtefaktFehler
     */
    public function anmelden(
        Worker $worker,
        Job $job,
        int $versuch,
        string $kindWert,
        int $seq,
        string $dekSealed,
        ?string $header,
        int $cipherSize,
        string $sha256Hex,
        \DateTimeImmutable $now,
    ): array {
        $documentId = self::dokumentDes($job);
        if ($versuch !== $job->attempts) {
            throw new WorkerArtefaktFehler('versuch_veraltet', 409);
        }
        $kind = ArtifactKind::tryFrom($kindWert);
        if ($kind === null || !in_array($kind, self::erlaubteKinds($job->typ), true)) {
            throw new WorkerArtefaktFehler('kind_unzulaessig', 422);
        }
        if ($seq < 0 || $seq > self::MAX_SEQ) {
            throw new WorkerArtefaktFehler('seq_ungueltig', 422);
        }
        $tresor = $this->vaults->current() ?? throw new WorkerArtefaktFehler('kein_tresor', 409);
        // Sealed to the CURRENT VK_pub (issue #90: after a rotation started,
        // that is the new generation) - anything else could not be opened.
        if (!WorkerKrypto::istTresorDek($dekSealed, $tresor->version)) {
            throw new WorkerArtefaktFehler('dek_ungueltig', 422);
        }
        $art = self::art($kind);
        if ($art === WorkerUpload::ART_BLOB) {
            if ($header === null || !WorkerKrypto::istBlobHeader($header)) {
                throw new WorkerArtefaktFehler('header_ungueltig', 422);
            }
            if ($cipherSize > self::BLOB_MAX_BYTES) {
                throw new WorkerArtefaktFehler('zu_gross', 413);
            }
            if (WorkerKrypto::klartextGroesse($cipherSize) === null) {
                throw new WorkerArtefaktFehler('groesse_ungueltig', 422);
            }
        } else {
            $header = null;
            if ($cipherSize > self::DATEN_MAX_BYTES) {
                throw new WorkerArtefaktFehler('zu_gross', 413);
            }
            if ($cipherSize < 1 + 24 + 16) {
                throw new WorkerArtefaktFehler('groesse_ungueltig', 422);
            }
        }
        if ($art === WorkerUpload::ART_BLOB) {
            if (preg_match('/^[0-9a-f]{64}$/', $sha256Hex) !== 1) {
                throw new WorkerArtefaktFehler('pruefsumme_ungueltig', 422);
            }
            $sha256 = (string) hex2bin($sha256Hex);
        } else {
            // A `data_enc` depends on its AAD, i.e. on the row id this very
            // announcement hands out: the worker cannot know its checksum
            // yet. Its length it does know (FieldCipher adds a fixed
            // overhead), and every chunk body is covered by the request
            // signature anyway.
            $sha256 = str_repeat("\0", 32);
        }

        if ($this->artefakte->vorhanden($documentId, $kind, $job->id, $seq)) {
            return ['status' => 'vorhanden'];
        }

        $offen = $this->artefakte->reserviert($documentId, $kind, $job->id, $seq);
        if ($offen !== null) {
            $upload = $this->uploads->find($offen);
            $zeile = $this->artefakte->rohzeile($offen);
            // The same announcement again (its answer got lost): carry on
            // where it stands. Anything else - an older attempt, other
            // bytes - is replaced.
            if ($upload !== null && $zeile !== null && $upload->workerId === $worker->id && $upload->attempt === $job->attempts
                && $upload->cipherSize === $cipherSize && hash_equals($upload->cipherSha256, $sha256)
                && hash_equals($zeile['dek_sealed'], $dekSealed) && $upload->header === $header) {
                return $this->antwort($upload);
            }
            if ($upload !== null) {
                $this->verwerfen($upload);
            } else {
                $this->artefakte->reservierungVerwerfen($offen);
            }
        }

        // Uploads of claims the worker no longer holds (lock ran out, job
        // went elsewhere) are dead weight - they must not use up its quota.
        foreach ($this->uploads->fuerWorker($worker->id) as $alt) {
            $altJob = $this->jobs->find($alt->jobId);
            if ($altJob === null || $altJob->status !== JobStatus::Laeuft || $altJob->attempts !== $alt->attempt
                || !str_starts_with($altJob->lockedBy ?? '', $worker->lockPraefix())) {
                $this->verwerfen($alt);
            }
        }
        if ($this->uploads->zaehleFuerWorker($worker->id) >= self::MAX_OFFENE) {
            throw new WorkerArtefaktFehler('zu_viele_uploads', 429);
        }

        $artefaktId = $this->artefakte->reserviere($documentId, $kind, $seq, $dekSealed, $job->id, $now)
            ?? throw new WorkerArtefaktFehler('konflikt', 409);
        try {
            $ticket = $this->ablage->create($cipherSize, $now);
            $upload = new WorkerUpload($artefaktId, $worker->id, $job->id, $job->attempts, $art, $header, $cipherSize, $sha256, $ticket->id);
            $this->uploads->anlegen($upload, $now);
        } catch (\Throwable $e) {
            if (isset($ticket)) {
                $this->ablage->discard($ticket->id);
            }
            $this->artefakte->reservierungVerwerfen($artefaktId);

            throw $e instanceof UploadException ? new WorkerArtefaktFehler('zu_gross', 413) : $e;
        }

        return $this->antwort($upload);
    }

    /**
     * Step 2: one piece of ciphertext.
     *
     * @param resource $stream
     *
     * @return array<string, mixed>
     *
     * @throws WorkerArtefaktFehler
     */
    public function chunk(Worker $worker, Job $job, int $artefaktId, int $index, $stream): array
    {
        $upload = $this->eigenerUpload($worker, $job, $artefaktId);
        try {
            $this->ablage->writeChunk($upload->uploadId, $index, $stream);
        } catch (UploadException $e) {
            throw new WorkerArtefaktFehler($e->error->value, 422);
        }

        return $this->antwort($upload);
    }

    /**
     * Step 3: checks and makes the artifact visible.
     *
     * @return array<string, mixed>
     *
     * @throws WorkerArtefaktFehler
     */
    public function festschreiben(Worker $worker, Job $job, int $artefaktId): array
    {
        $upload = $this->uploads->find($artefaktId);
        if ($upload === null) {
            // A repeat of a commit whose answer got lost.
            $zeile = $this->artefakte->rohzeile($artefaktId);
            if ($zeile !== null && $zeile['job_id'] === $job->id && $zeile['document_id'] === $job->refId
                && !str_starts_with($zeile['kind'], DocumentArtifactRepository::PENDING)) {
                return ['status' => 'vorhanden', 'artefakt_id' => $artefaktId];
            }

            throw new WorkerArtefaktFehler('upload_unbekannt', 404);
        }
        $upload = $this->eigenerUpload($worker, $job, $artefaktId);
        $zeile = $this->artefakte->rohzeile($artefaktId);
        $kind = $zeile === null ? null : ArtifactKind::tryFrom(substr($zeile['kind'], strlen(DocumentArtifactRepository::PENDING)));
        if ($zeile === null || $kind === null || !str_starts_with($zeile['kind'], DocumentArtifactRepository::PENDING)) {
            throw new WorkerArtefaktFehler('upload_unbekannt', 404);
        }

        // Announced under an older vault generation, and a key rotation
        // (issue #90) has since made a new one current: a blob stored now
        // could slip past the rotation's re-sealing. Start over with the
        // current VK_pub from the job's context.
        $tresor = $this->vaults->current();
        if ($tresor === null || !WorkerKrypto::istTresorDek($zeile['dek_sealed'], $tresor->version)) {
            $this->verwerfen($upload);

            throw new WorkerArtefaktFehler('dek_veraltet', 409);
        }

        try {
            $stand = $this->ablage->status($upload->uploadId);
        } catch (UploadException) {
            $this->verwerfen($upload);

            throw new WorkerArtefaktFehler('upload_unbekannt', 404);
        }
        if (!$stand->vollstaendig() || $stand->empfangen !== $upload->cipherSize) {
            throw new WorkerArtefaktFehler('unvollstaendig', 409);
        }

        if ($upload->art === WorkerUpload::ART_BLOB) {
            $groesse = WorkerKrypto::klartextGroesse($upload->cipherSize) ?? throw new WorkerArtefaktFehler('groesse_ungueltig', 422);
            try {
                $blob = $this->blobs->storeCipher(
                    $this->inhalt($upload->uploadId),
                    $zeile['dek_sealed'],
                    (string) $upload->header,
                    $groesse,
                    $upload->cipherSha256,
                    BlobService::configuredStorage($this->settings),
                );
            } catch (BlobException) {
                $this->verwerfen($upload);

                throw new WorkerArtefaktFehler('pruefsumme_falsch', 422);
            }
            if (!$this->artefakte->festschreiben($artefaktId, $kind, $blob->id, null)) {
                $this->blobs->delete($blob);

                throw new WorkerArtefaktFehler('konflikt', 409);
            }
        } else {
            $daten = '';
            foreach ($this->inhalt($upload->uploadId) as $stueck) {
                $daten .= $stueck;
            }
            if (strlen($daten) !== $upload->cipherSize || !WorkerKrypto::istFeldChiffrat($daten)) {
                $this->verwerfen($upload);

                throw new WorkerArtefaktFehler('pruefsumme_falsch', 422);
            }
            if (!$this->artefakte->festschreiben($artefaktId, $kind, null, $daten)) {
                throw new WorkerArtefaktFehler('konflikt', 409);
            }
        }

        $this->uploads->loesche($artefaktId);
        $this->ablage->discard($upload->uploadId);

        return ['status' => 'fertig', 'artefakt_id' => $artefaktId];
    }

    /** Drops an unfinished upload: ciphertext, staging row, reserved artifact row. */
    public function verwerfen(WorkerUpload $upload): void
    {
        $this->ablage->discard($upload->uploadId);
        $this->artefakte->reservierungVerwerfen($upload->artifactId);
        $this->uploads->loesche($upload->artifactId);
    }

    /** The cron: announcements never committed, and stray ciphertext directories. */
    public function aufraeumen(\DateTimeImmutable $now): int
    {
        $vor = $now->setTimestamp($now->getTimestamp() - self::OFFEN_HOECHSTENS_SEKUNDEN);
        $anzahl = 0;
        foreach ($this->uploads->aelterAls($vor, 200) as $upload) {
            $this->verwerfen($upload);
            ++$anzahl;
        }

        return $anzahl + $this->ablage->cleanup($vor);
    }

    /**
     * @throws WorkerArtefaktFehler
     */
    private function eigenerUpload(Worker $worker, Job $job, int $artefaktId): WorkerUpload
    {
        $upload = $this->uploads->find($artefaktId);
        // One answer for "unknown", "another worker's" and "another job's or
        // attempt's": nothing to learn about uploads that are not one's own.
        if ($upload === null || $upload->workerId !== $worker->id || $upload->jobId !== $job->id
            || $upload->attempt !== $job->attempts || $job->status !== JobStatus::Laeuft) {
            throw new WorkerArtefaktFehler('upload_unbekannt', 404);
        }

        return $upload;
    }

    /**
     * @throws WorkerArtefaktFehler
     */
    private static function dokumentDes(Job $job): int
    {
        if ($job->refType !== 'document' || $job->refId === null) {
            throw new WorkerArtefaktFehler('job_ohne_dokument', 409);
        }

        return $job->refId;
    }

    /**
     * @return array<string, mixed>
     */
    private function antwort(WorkerUpload $upload): array
    {
        try {
            $stand = $this->ablage->status($upload->uploadId);
        } catch (UploadException) {
            // The ciphertext is gone (cleaned up): start over.
            $this->verwerfen($upload);

            throw new WorkerArtefaktFehler('upload_unbekannt', 404);
        }

        return [
            'status' => 'offen',
            'artefakt_id' => $upload->artifactId,
            'aad' => 'document_artifact|' . $upload->artifactId . '|data_enc',
            'chunks' => $stand->chunks,
            'chunk_bytes' => UploadService::CHUNK_BYTES,
            'fehlend' => $stand->fehlend,
        ];
    }

    /**
     * The uploaded ciphertext in pieces, read through one opened upload
     * (App\Service\Upload\OffenesUpload) and closed afterwards.
     *
     * @return \Generator<int, string>
     */
    private function inhalt(string $uploadId): \Generator
    {
        $offen = $this->ablage->oeffnen($uploadId);
        try {
            yield from $offen->chunks();
        } finally {
            $offen->schliessen();
        }
    }

}
