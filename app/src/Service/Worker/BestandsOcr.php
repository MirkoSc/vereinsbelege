<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\Job;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\Permission;
use App\Repository\ArchiveImportRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Job\HintergrundJob;
use App\Service\Job\JobSchrittErgebnis;
use App\Service\Processing\ProcessingException;

/**
 * OCR backfill of the imported archive through the worker
 * (docs/spec/07-worker.md section 6 "Stand #88", issue #88, E-55).
 *
 * The archive import (05 section 3) does not hand its documents to the
 * worker's OCR one by one (App\Service\Document\Texterkennung): 400 scans of
 * a year would sit in the worker's queue for hours, ahead of every new
 * receipt. Instead an admin starts this backfill for one import run or for
 * all of them. It is a session job (one at a time) that tops the worker's
 * OCR queue up to NACHSCHUB jobs - counting the new receipts' OCR jobs as
 * well, so these never wait behind more than a handful of archive scans -
 * and otherwise waits. Its cursor and counts live in `job.state`, so it
 * resumes wherever it stopped: tab closed, admin signed out, worker offline.
 *
 * It needs an unlocked vault, like every handover to the worker (the
 * grants, WorkerFreigaben::ausSession()) - JobRunner calls it only from a
 * signed-in session with `admin.settings`, the right of the worker page.
 *
 * Locked receipts are included: OCR adds a derived text layer (artifacts
 * and `ocr_status`, like `content_bi`, 01 section 7) and a PDF/A artifact
 * beside the files - the receipt's data, original and PDF stay untouched.
 */
final readonly class BestandsOcr implements HintergrundJob
{
    public const string JOB_TYP = 'bestand_ocr';

    /** Worker OCR jobs waiting or running at most before the backfill adds any. */
    public const int NACHSCHUB = 5;

    /** Candidates looked at per step at most. */
    private const int PRUEFEN_JE_SCHRITT = 25;

    /** The worker's queue is full: look again after this many seconds. */
    private const int WARTEN_VOLL = 20;

    /** Between two steps that still had candidates. */
    private const int WARTEN_WEITER = 2;

    /** The worker takes no OCR right now (module off, offline): look again later. */
    private const int WARTEN_OHNE_WORKER = 300;

    private const string SCHRITT = 'nachschub';

    public function __construct(
        private WorkerUebergabe $uebergabe,
        private DocumentRepository $documents,
        private JobRepository $jobs,
        private ArchiveImportRepository $imports,
    ) {
    }

    public function typ(): string
    {
        return self::JOB_TYP;
    }

    public function recht(): Permission
    {
        return Permission::AdminSettings;
    }

    /**
     * Starts the backfill for one finished import run ($importId) or for
     * every archive document (null). Returns null when one is running
     * already, the run is not finished or has no documents.
     */
    public function starten(?int $importId, \DateTimeImmutable $now): ?int
    {
        [$von, $bis] = [0, 0];
        if ($importId !== null) {
            $import = $this->imports->find($importId);
            // Only a finished (or stopped) run: one still running writes
            // documents beyond the range known now.
            if ($import === null || $import->status->offen()
                || $import->stats->ersteDocumentId === null || $import->stats->letzteDocumentId === null) {
                return null;
            }
            [$von, $bis] = [$import->stats->ersteDocumentId, $import->stats->letzteDocumentId];
        }

        // Check and insert under one named lock: two clicks at once start one run.
        return $this->jobs->unterSperre(self::JOB_TYP, fn(): ?int => $this->laufender() !== null ? null : $this->jobs->enqueue(self::JOB_TYP, JobExecutor::Session, state: [
            'import' => $importId ?? 0,
            'von' => $von,
            'bis' => $bis,
            'nach' => 0,
            'uebergeben' => 0,
            'ausgelassen' => 0,
        ], now: $now));
    }

    /** The running backfill (`offen`/`laeuft`), or null. */
    public function laufender(): ?Job
    {
        $job = $this->jobs->letzterVomTyp(self::JOB_TYP);

        return $job !== null && in_array($job->status, [JobStatus::Offen, JobStatus::Laeuft], true) ? $job : null;
    }

    /** The newest backfill, running or finished (until the cron cleans it up). */
    public function letzter(): ?Job
    {
        return $this->jobs->letzterVomTyp(self::JOB_TYP);
    }

    /** "Abbrechen": the jobs already at the worker finish, nothing new follows. */
    public function abbrechen(\DateTimeImmutable $now): bool
    {
        $job = $this->laufender();

        return $job !== null && $this->jobs->abbrechen($job->id, $now);
    }

    /**
     * Progress over the backfill's documents.
     *
     * @return array{gesamt: int, mitText: int, inErkennung: int, beimWorker: int, fehlgeschlagen: int, offen: int}
     */
    public function stand(Job $job): array
    {
        $state = self::zustand($job);

        return $this->documents->bestandOcrStand($state['von'], $state['bis'], $job->createdAt);
    }

    public function schritt(Job $job, Vault $vault, \DateTimeImmutable $now): JobSchrittErgebnis
    {
        $state = self::zustand($job);
        // Without the worker, or with a vault no grant is made from
        // (rotation, an older generation), nothing can go - for every
        // document alike: wait, the cursor stays.
        if (!$this->uebergabe->verfuegbar(WorkerUebergabe::OCR, $now) || !$this->uebergabe->freigabeMoeglich($vault)) {
            return JobSchrittErgebnis::warten(self::SCHRITT, $state, self::WARTEN_OHNE_WORKER);
        }
        // New receipts' OCR jobs count as well: the backfill only fills
        // what they leave free.
        $frei = self::NACHSCHUB - $this->jobs->zaehleOffen(JobExecutor::Worker, [WorkerUebergabe::OCR]);
        if ($frei <= 0) {
            return JobSchrittErgebnis::warten(self::SCHRITT, $state, self::WARTEN_VOLL);
        }

        $ids = $this->documents->bestandOcrNachholbar($state['von'], $state['bis'], $job->createdAt, $state['nach'], self::PRUEFEN_JE_SCHRITT);
        if ($ids === []) {
            return $this->ende($job, $state);
        }
        foreach ($ids as $documentId) {
            try {
                $executor = $this->uebergabe->einreihen(WorkerUebergabe::OCR, null, $documentId, $vault, $now);
            } catch (CryptoException) {
                // A data key of this document does not open: it cannot go,
                // the others still can.
                $executor = null;
            }
            if ($executor === JobExecutor::Worker) {
                $state['nach'] = $documentId;
                ++$state['uebergeben'];
                if (--$frei <= 0) {
                    return JobSchrittErgebnis::warten(self::SCHRITT, $state, self::WARTEN_VOLL);
                }
                continue;
            }
            // The worker went away meanwhile: this document stays ahead of
            // the cursor for when it is back.
            if (!$this->uebergabe->verfuegbar(WorkerUebergabe::OCR, $now)) {
                return JobSchrittErgebnis::warten(self::SCHRITT, $state, self::WARTEN_OHNE_WORKER);
            }
            // The cursor walks past a document that cannot go (no readable
            // blob): tried once per pass, never in a loop.
            $state['nach'] = $documentId;
            ++$state['ausgelassen'];
        }

        // A short pause instead of weiter(): the session runner claims the
        // oldest job first, and the receipts' own jobs must not wait for a
        // long stretch of archive documents.
        return JobSchrittErgebnis::warten(self::SCHRITT, $state, self::WARTEN_WEITER);
    }

    /**
     * The cursor reached the end. Documents in the range may still become
     * candidates: their own text detection is pending, or a handed-over OCR
     * job may still fail or fall back. While either holds, another pass
     * starts from the beginning; otherwise the run is done. A document whose
     * OCR job failed in this run is not a candidate again (it cannot loop).
     *
     * @param array{import: int, von: int, bis: int, nach: int, uebergeben: int, ausgelassen: int} $state
     */
    private function ende(Job $job, array $state): JobSchrittErgebnis
    {
        $stand = $this->documents->bestandOcrStand($state['von'], $state['bis'], $job->createdAt);
        if ($stand['inErkennung'] === 0 && $stand['beimWorker'] === 0) {
            return JobSchrittErgebnis::fertig();
        }

        return JobSchrittErgebnis::warten(self::SCHRITT, ['nach' => 0, 'ausgelassen' => 0] + $state, self::WARTEN_VOLL);
    }

    /**
     * @return array{import: int, von: int, bis: int, nach: int, uebergeben: int, ausgelassen: int}
     */
    public static function zustand(Job $job): array
    {
        $zustand = [];
        foreach (['import', 'von', 'bis', 'nach', 'uebergeben', 'ausgelassen'] as $schluessel) {
            $wert = $job->state[$schluessel] ?? null;
            if (!is_int($wert) || $wert < 0) {
                throw new ProcessingException('Zwischenstand passt nicht zum Job.');
            }
            $zustand[$schluessel] = $wert;
        }

        return $zustand;
    }
}
