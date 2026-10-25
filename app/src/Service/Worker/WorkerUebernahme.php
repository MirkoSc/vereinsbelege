<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Domain\DocumentArtifact;
use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\Permission;
use App\Repository\BlobRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Document\KiAuslesung;
use App\Service\Document\PdfRasterung;
use App\Service\Document\Texterkennung;
use App\Service\Job\JobHandler;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Processing\ProcessingException;

/**
 * Takes the results of a finished worker job into the processing flow
 * (docs/spec/07-worker.md section 5 "Stand M7b-3", issue #53, E-43). The
 * worker's artifacts are sealed to the vault, so checking them needs an
 * unlocked vault: `complete` queues this session job, and the next
 * signed-in session runs it.
 *
 * Every artifact of the worker run is checked first. One that does not
 * open or lacks the shape the session's own artifacts have is marked
 * defective (DocumentArtifactRepository::DEFEKT - hidden from every reader,
 * counted on the admin page), never trusted and never retried as is. Then:
 *   - `ocr`: usable OCR text -> `ocr_status` `fertig` (the AI reads the
 *     text); else the document stays on its page images.
 *   - `ai_extract`: the last well-formed attempt ends the run as the
 *     session's chain would; none -> the session run is queued (fallback).
 *   - `render_pages`: a run with any defective page is dropped entirely and
 *     the browser renders the pages (fallback).
 *   - `image_cleanup`: the PDF/A is checked, nothing else follows.
 * The readers themselves treat a worker artifact that does not open as
 * missing (App\Service\Document\ArtefaktJson) - so a defect never breaks
 * the AI or the full-text index, even before this job ran.
 */
final readonly class WorkerUebernahme implements JobHandler
{
    public const string JOB_TYP = 'worker_uebernahme';

    private const string REF_TYPE = 'document';

    public function __construct(
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private BlobRepository $blobs,
        private JobRepository $jobs,
        private Texterkennung $texterkennung,
        private KiAuslesung $kiAuslesung,
    ) {
    }

    /** Queued by the worker API when a worker job completes. */
    public static function einreihen(JobRepository $jobs, Job $workerJob, \DateTimeImmutable $now): void
    {
        if ($workerJob->refType !== self::REF_TYPE || $workerJob->refId === null) {
            return;
        }
        $jobs->enqueue(
            self::JOB_TYP,
            JobExecutor::Session,
            self::REF_TYPE,
            $workerJob->refId,
            ['job' => $workerJob->id, 'typ' => $workerJob->typ],
            now: $now,
        );
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    /** `document.edit`, like the jobs whose results it takes over. */
    public function recht(): Permission
    {
        return Permission::DocumentEdit;
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $workerJobId = $job->state['job'] ?? null;
        $typ = $job->state['typ'] ?? null;
        if ($job->refType !== self::REF_TYPE || $job->refId === null || !is_int($workerJobId) || !is_string($typ)) {
            throw new ProcessingException('Zwischenstand passt nicht zum Job.');
        }
        $document = $this->documents->find($job->refId);
        if ($document === null) {
            return JobSchrittErgebnis::uebersprungen();
        }

        match ($typ) {
            WorkerUebergabe::OCR => $this->ocr($document, $workerJobId, $vault),
            KiAuslesung::JOB_TYP => $this->kiAuslesen($document, $workerJobId, $vault, $now),
            PdfRasterung::JOB_TYP => $this->seitenbilder($document, $workerJobId, $vault, $now),
            'image_cleanup' => $this->pruefeBlobs($document, ArtifactKind::Pdfa, $workerJobId, $vault),
            default => throw new ProcessingException('Unbekannter Worker-Job-Typ.'),
        };

        return JobSchrittErgebnis::fertig();
    }

    private function ocr(Document $document, int $workerJobId, Vault $vault): void
    {
        $defekt = false;
        foreach ($this->lauf($document, ArtifactKind::Text, $workerJobId) as $artefakt) {
            if ($this->texterkennung->workerText($artefakt, $vault) === null) {
                $this->artifacts->alsDefektMarkieren($artefakt->id);
                $defekt = true;
            }
        }
        $this->pruefeBlobs($document, ArtifactKind::Pdfa, $workerJobId, $vault);
        // With one text broken the rest does not cover every original: the
        // document stays on its page images and keeps its own text layer.
        if (!$defekt) {
            $this->texterkennung->workerOcrUebernehmen($document, $workerJobId, $vault);
        }
    }

    private function kiAuslesen(Document $document, int $workerJobId, Vault $vault, \DateTimeImmutable $now): void
    {
        foreach ($this->lauf($document, ArtifactKind::Extraction, $workerJobId) as $artefakt) {
            if ($this->kiAuslesung->workerVersuch($artefakt, $vault) === null) {
                $this->artifacts->alsDefektMarkieren($artefakt->id);
            }
        }
        if (!$this->kiAuslesung->workerLaufUebernehmen($document, $workerJobId, $vault, $now)) {
            // Nothing usable from the worker: the session asks the AI itself
            // (it skips a receipt that was taken up meanwhile).
            KiAuslesung::einreihen($this->jobs, $document->id, $now);
        }
    }

    private function seitenbilder(Document $document, int $workerJobId, Vault $vault, \DateTimeImmutable $now): void
    {
        $seiten = $this->lauf($document, ArtifactKind::PageImage, $workerJobId);
        $defekt = $seiten === [];
        foreach ($seiten as $artefakt) {
            $defekt = $defekt || !$this->blobLesbar($artefakt, $vault);
        }
        if (!$defekt) {
            return;
        }
        // Half a rendering is worse than none: the AI would see some pages.
        foreach ($seiten as $artefakt) {
            $this->artifacts->alsDefektMarkieren($artefakt->id);
        }
        if (!$this->jobs->offenFuer([PdfRasterung::JOB_TYP], self::REF_TYPE, $document->id)) {
            $this->jobs->enqueue(PdfRasterung::JOB_TYP, JobExecutor::Browser, self::REF_TYPE, $document->id, now: $now);
        }
    }

    private function pruefeBlobs(Document $document, ArtifactKind $kind, int $workerJobId, Vault $vault): void
    {
        foreach ($this->lauf($document, $kind, $workerJobId) as $artefakt) {
            if (!$this->blobLesbar($artefakt, $vault)) {
                $this->artifacts->alsDefektMarkieren($artefakt->id);
            }
        }
    }

    /**
     * The blob is stored completely and its data key opens with the vault.
     * The content itself is not decrypted here (it may be large); the
     * secretstream's authentication catches a damaged stream when read.
     */
    private function blobLesbar(DocumentArtifact $artefakt, Vault $vault): bool
    {
        $blob = $artefakt->blobId === null ? null : $this->blobs->find($artefakt->blobId);
        if ($blob === null || !$blob->isComplete()) {
            return false;
        }
        try {
            $vault->openDataKey($blob->dekSealed);
        } catch (CryptoException | \SodiumException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<DocumentArtifact> the worker run's artifacts of $kind
     */
    private function lauf(Document $document, ArtifactKind $kind, int $workerJobId): array
    {
        return array_values(array_filter(
            $this->artifacts->fuerDokument($document->id, $kind),
            static fn(DocumentArtifact $a): bool => $a->jobId === $workerJobId && $a->producer === JobExecutor::Worker,
        ));
    }
}
