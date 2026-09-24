<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\ArtifactKind;
use App\Domain\BlobMeta;
use App\Domain\Document;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Permission;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Job\JobTyp;
use App\Service\Processing\JpegInfo;
use App\Service\Processing\ProcessingException;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The `render_pages` browser job (issue #30/M4-8, docs/spec/03-erfassung-
 * und-ki.md section 3, docs/spec/06-betrieb.md section 4): renders every
 * PDF original of a document to page images with pdf.js, because the
 * server has no CLI tool for it (CLAUDE.md section 1). Unlike
 * `pdf_erzeugen` (App\Service\Document\PdfErzeugung, a `JobHandler`
 * driven entirely server-side), the work itself happens in the browser -
 * this class only claims the task, hands out the source PDF, and stores
 * what comes back. It therefore implements App\Service\Job\JobTyp, not
 * JobHandler: there is no single schritt() a runner calls.
 *
 * One job per document, covering every PDF original in one run (not one job
 * per file): `job.state` carries the list of source blob ids (`quellen`)
 * plus where the run currently stands (`quelle`, `seite`, `seq`, and
 * `letzte` - the last page actually stored, so a retried upload of it can be
 * answered without storing it twice). `seq` numbers pages globally across
 * every source, matching `document_artifact.seq`
 * (migrations/015_document_artifact.sql).
 *
 * Framework-free (CLAUDE.md section 6a): no Http, no Session. Driven by
 * App\Api\RasterungController from `public/js/rasterung.js`, which runs only
 * on the inbox pages (docs/spec/03-erfassung-und-ki.md section 3: "während
 * ein angemeldeter Nutzer den Posteingang geöffnet hat").
 */
final readonly class PdfRasterung implements JobTyp
{
    public const string JOB_TYP = 'render_pages';

    private const string REF_TYPE = 'document';

    /** A shared-hosting request's budget with a lot of margin: unlike a
     * session job's one-call-per-step, one claim here stays valid across
     * many small page uploads (App\Repository\JobRepository::fortschritt()
     * extends it on every one), so it only has to outlast the gap between
     * two of them, not the whole job. */
    private const int LOCK_SECONDS = 120;

    /** Same reasoning as App\Service\Job\JobRunner::MAX_VERSUCHE: a job
     * claimed this often without ever storing a single page is not going to
     * get through. */
    private const int MAX_VERSUCHE = 3;

    private const int MAX_SEITEN = 200;

    /** UploadService::CHUNK_BYTES: a rendered page has no reason to be
     * larger than one upload chunk. */
    private const int MAX_BYTES = 2 * 1024 * 1024;

    private const int MAX_PIXEL = 4000;

    public function __construct(
        private JobRepository $jobs,
        private DocumentRepository $documents,
        private BlobRepository $blobs,
        private BlobService $blobService,
        private DocumentArtifactRepository $artifacts,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /**
     * `document.edit` (same right as `pdf_erzeugen` and the inbox decision
     * itself): whoever may decide on a receipt is who may drive its
     * rendering.
     */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    /**
     * Claims the oldest `render_pages` job, or reports there is none. The
     * caller's right is already checked by the route
     * (`Zugriff::recht(Permission::DocumentEdit)`) - unlike App\Service\Job\
     * JobRunner, which serves several session job types behind one generic
     * "logged in" route, this is the only job type behind this one.
     */
    public function naechste(Vault $vault, \DateTimeImmutable $now): RasterAufgabe
    {
        $lockedBy = 'browser:' . bin2hex(random_bytes(8));
        $job = $this->jobs->claim(JobExecutor::Browser, $lockedBy, self::LOCK_SECONDS, typ: self::JOB_TYP, now: $now);
        if ($job === null) {
            return RasterAufgabe::leer();
        }

        if ($job->attempts > self::MAX_VERSUCHE) {
            $this->jobs->fail($job->id, PdfRasterungFehlgeschlagen::class, $now, $lockedBy);

            return RasterAufgabe::leer();
        }

        $quellen = self::intListe($job->state['quellen'] ?? []);
        if ($quellen === []) {
            // First claim of a fresh job: nothing has been resolved yet -
            // decide which of the document's originals need rendering and
            // remember it. Genuine progress, so this is the one place a
            // fresh claim resets `attempts` before a single page exists.
            $document = $this->dokument($job);
            $quellen = $this->pdfQuellen($document, $vault);
            if ($quellen === []) {
                // Nothing to do (e.g. the job outlived the document's only
                // PDF original some other way) - not an error, nothing to retry.
                $this->jobs->schrittErledigt($job->id, $lockedBy, JobSchrittErgebnis::uebersprungen(), $now);

                return RasterAufgabe::leer();
            }

            $this->jobs->fortschritt(
                $job->id,
                $lockedBy,
                ['quellen' => $quellen, 'quelle' => 0, 'seite' => 1, 'seq' => 0],
                self::LOCK_SECONDS,
                $now,
            );
            $quelle = 0;
            $seite = 1;
        } else {
            // Resuming a job an earlier attempt (this tab or another one)
            // already started: pick up exactly where it left off. Nothing
            // is written here - only storing a page counts as progress.
            $quelle = (int) ($job->state['quelle'] ?? 0);
            $seite = (int) ($job->state['seite'] ?? 1);
        }

        if (!isset($quellen[$quelle])) {
            // Defensive: every source already has all its pages, only the
            // final schrittErledigt() call never landed. Nothing left to
            // hand out.
            $this->jobs->schrittErledigt($job->id, $lockedBy, JobSchrittErgebnis::fertig(), $now);

            return RasterAufgabe::leer();
        }

        return RasterAufgabe::aufgabe($job->id, $lockedBy, $quellen[$quelle], $quelle, $seite, count($quellen));
    }

    /**
     * The raw bytes of the source PDF a claimed task points at, for the
     * browser to feed to pdf.js. Null when the lock no longer matches (job
     * finished, lost, or the caller names the wrong source) - the browser
     * has to fetch a fresh task rather than trust a stale one.
     *
     * @return ?array{chunks: iterable<string>, mime: string}
     */
    public function quelle(int $jobId, string $lock, int $quelleIndex, Vault $vault): ?array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->lockedBy !== $lock || $job->status !== JobStatus::Laeuft) {
            return null;
        }

        $quellen = self::intListe($job->state['quellen'] ?? []);
        if (!isset($quellen[$quelleIndex])) {
            return null;
        }

        $blob = $this->blobs->find($quellen[$quelleIndex]);
        if ($blob === null || !$blob->isComplete()) {
            return null;
        }

        $meta = $this->blobService->meta($blob, $vault);

        return ['chunks' => $this->blobService->openRead($blob, $vault), 'mime' => $meta?->mimeType ?? MagicBytes::PDF];
    }

    /**
     * Stores one rendered page, checked against exactly what this job
     * currently expects. A retry of the page just stored is answered
     * without storing it again (`letzte` in the job state); anything else
     * that does not match is `unerwartet` - the browser has to ask
     * naechste() again rather than guess.
     */
    public function seiteSpeichern(
        int $jobId,
        string $lock,
        int $quelleIndex,
        int $seite,
        int $seitenGesamt,
        string $jpeg,
        Vault $vault,
        \DateTimeImmutable $now,
    ): SeiteErgebnis {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return SeiteErgebnis::verloren();
        }
        if ($job->status === JobStatus::Fertig) {
            // The whole job is already done - any retry of its last page is
            // a harmless no-op, not an error.
            return SeiteErgebnis::fertig();
        }
        if ($job->lockedBy !== $lock || $job->status !== JobStatus::Laeuft) {
            return SeiteErgebnis::verloren();
        }

        $quellen = self::intListe($job->state['quellen'] ?? []);
        if (
            !isset($quellen[$quelleIndex])
            || $seite < 1 || $seite > self::MAX_SEITEN
            || $seitenGesamt < 1 || $seitenGesamt > self::MAX_SEITEN
            || $seite > $seitenGesamt
        ) {
            return SeiteErgebnis::unerwartet();
        }

        $erwarteteQuelle = (int) ($job->state['quelle'] ?? -1);
        $erwarteteSeite = (int) ($job->state['seite'] ?? -1);
        $letzte = $job->state['letzte'] ?? null;

        if ($quelleIndex !== $erwarteteQuelle || $seite !== $erwarteteSeite) {
            if (
                is_array($letzte)
                && ($letzte['quelle'] ?? null) === $quelleIndex
                && ($letzte['seite'] ?? null) === $seite
            ) {
                // The page just stored, uploaded again - already done; tell
                // the browser what it already knows is next rather than
                // making it re-derive it.
                return SeiteErgebnis::ok($erwarteteQuelle, $erwarteteSeite);
            }

            return SeiteErgebnis::unerwartet();
        }

        if ($jpeg === '') {
            return SeiteErgebnis::ungueltigerTyp();
        }
        if (strlen($jpeg) > self::MAX_BYTES) {
            return SeiteErgebnis::zuGross();
        }
        if (MagicBytes::detect(substr($jpeg, 0, MagicBytes::HEAD_BYTES)) !== MagicBytes::JPEG) {
            return SeiteErgebnis::ungueltigerTyp();
        }

        try {
            $info = JpegInfo::aus($jpeg);
        } catch (ProcessingException) {
            return SeiteErgebnis::ungueltigerTyp();
        }
        if ($info->width > self::MAX_PIXEL || $info->height > self::MAX_PIXEL) {
            return SeiteErgebnis::zuGross();
        }

        $document = $this->dokument($job);
        $seq = (int) ($job->state['seq'] ?? 0);

        // Belt and braces against a genuine race (two tabs holding what
        // each believes is the lock cannot happen, but a job re-claimed
        // after this holder's lock silently expired could still be driven
        // by two callers briefly) - the unique key in
        // migrations/015_document_artifact.sql is the real backstop.
        if (!$this->artifacts->vorhanden($document->id, ArtifactKind::PageImage, $job->id, $seq)) {
            $blob = $this->blobService->storeString(
                $jpeg,
                new BlobMeta(MagicBytes::JPEG, width: $info->width, height: $info->height),
                $vault,
            );
            $this->artifacts->insert(
                $document->id,
                ArtifactKind::PageImage,
                $seq,
                $blob->id,
                $vault->sealDataKey(DataKey::generate()),
                null,
                JobExecutor::Browser,
                $job->id,
                $now,
            );
        }

        $letzteSeiteDerQuelle = $seite >= $seitenGesamt;
        $naechsteQuelle = $letzteSeiteDerQuelle ? $quelleIndex + 1 : $quelleIndex;
        $naechsteSeite = $letzteSeiteDerQuelle ? 1 : $seite + 1;

        if ($naechsteQuelle >= count($quellen)) {
            $this->raeumeAlteAuf($document->id, $job->id);
            $this->jobs->schrittErledigt($job->id, $lock, JobSchrittErgebnis::fertig(), $now);

            return SeiteErgebnis::fertig();
        }

        $this->jobs->fortschritt(
            $job->id,
            $lock,
            [
                'quellen' => $quellen,
                'quelle' => $naechsteQuelle,
                'seite' => $naechsteSeite,
                'seq' => $seq + 1,
                'letzte' => ['quelle' => $quelleIndex, 'seite' => $seite],
            ],
            self::LOCK_SECONDS,
            $now,
        );

        return SeiteErgebnis::ok($naechsteQuelle, $naechsteSeite);
    }

    /**
     * The browser gives up on this task (pdf.js could not load the file at
     * all, the file needs a password, or the tab is simply done with it for
     * now). Returns false when the lock no longer matches - the caller has
     * nothing to undo.
     */
    public function abbrechen(int $jobId, string $lock, string $grund, \DateTimeImmutable $now): bool
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->lockedBy !== $lock || $job->status !== JobStatus::Laeuft) {
            return false;
        }

        match ($grund) {
            'defekt' => $this->jobs->fail($job->id, PdfRasterungFehlgeschlagen::class, $now, $lock),
            'passwort' => $this->jobs->fail($job->id, PdfPasswortGeschuetzt::class, $now, $lock),
            'browser' => $this->jobs->release($job->id, $now, $lock),
            default => throw new ProcessingException(sprintf('Unbekannter Abbruchgrund "%s".', $grund)),
        };

        return true;
    }

    /**
     * The PDF originals of a document that still need rendering - every
     * original whose stored MIME type is PDF (App\Service\Document\
     * PdfErzeugung already established that at least one exists before
     * this job was ever queued).
     *
     * @return list<int>
     */
    private function pdfQuellen(Document $document, Vault $vault): array
    {
        $quellen = [];
        foreach ($document->originalBlobIds as $blobId) {
            $blob = $this->blobs->find($blobId);
            $meta = $blob === null ? null : $this->blobService->meta($blob, $vault);
            if ($meta?->mimeType === MagicBytes::PDF) {
                $quellen[] = $blobId;
            }
        }

        return $quellen;
    }

    /**
     * Drops the page images of every OTHER rendering run of this document -
     * a re-render (a later job of the same type on the same document) makes
     * an earlier one's pages stale. Deleting the blob removes the artifact
     * row with it (`ON DELETE CASCADE`, migrations/015_document_artifact.sql).
     */
    private function raeumeAlteAuf(int $documentId, int $jobId): void
    {
        foreach ($this->artifacts->fremdeBlobIds($documentId, ArtifactKind::PageImage, $jobId) as $blobId) {
            $blob = $this->blobs->find($blobId);
            if ($blob !== null) {
                $this->blobService->delete($blob);
            }
        }
    }

    private function dokument(Job $job): Document
    {
        if ($job->refType !== self::REF_TYPE || $job->refId === null) {
            throw new ProcessingException('Job ohne zugehöriges Dokument.');
        }

        return $this->documents->find($job->refId) ?? throw new ProcessingException('Dokument nicht gefunden.');
    }

    /**
     * @return list<int>
     */
    private static function intListe(mixed $wert): array
    {
        return is_array($wert) ? array_values(array_map(intval(...), $wert)) : [];
    }
}
