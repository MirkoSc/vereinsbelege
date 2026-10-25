<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\JobExecutor;
use App\Domain\Zugriffsbereich;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Service\Crypto\Vault;

/**
 * Queues a worker-capable step of a document on the route WorkerRouting
 * picks (docs/spec/07-worker.md section 6, issue #53/M7b-3, E-43).
 *
 * Every such step is queued from a signed-in session: `render_pages` by
 * `pdf_erzeugen`, `ocr` by `extract_text`, `ai_extract` when a receipt is
 * accepted, "OCR nachholen" from the admin page. The session holds the
 * unlocked vault, so the worker grants are made right here
 * (WorkerFreigaben::ausSession()) - for exactly the documents that go to the
 * worker. If granting is refused (vault key rotation, issue #90) the step
 * takes the browser/session route, as without a worker.
 */
final readonly class WorkerUebergabe
{
    /** The worker's OCR job type (section 2) - no browser/session counterpart. */
    public const string OCR = 'ocr';

    /** "OCR nachholen": candidates looked at per call at most. */
    private const int NACHHOLEN_PRUEFEN = 500;

    public function __construct(
        private WorkerRouting $routing,
        private WorkerFreigaben $freigaben,
        private JobRepository $jobs,
        private DocumentRepository $documents,
    ) {
    }

    /**
     * Queues $typ for $documentId: with the worker when it should run it,
     * else with $ersatz - or not at all when $ersatz is null (`ocr`, which
     * has no browser/session counterpart, section 2: "entfällt"). Returns
     * the executor the job was queued for, or null when none was queued
     * (also when one of $typ is still pending for the document).
     */
    public function einreihen(string $typ, ?JobExecutor $ersatz, int $documentId, Vault $vault, \DateTimeImmutable $now): ?JobExecutor
    {
        // One pending job per type and document - a repeated step queues
        // nothing twice.
        if ($this->jobs->offenFuer([$typ], 'document', $documentId)) {
            return null;
        }
        if ($this->anWorker($typ, $documentId, $vault, $now)) {
            $this->jobs->enqueue($typ, JobExecutor::Worker, 'document', $documentId, now: $now);

            return JobExecutor::Worker;
        }
        if ($ersatz === null) {
            return null;
        }
        $this->jobs->enqueue($typ, $ersatz, 'document', $documentId, now: $now);

        return $ersatz;
    }

    /** Whether the worker would take a new job of $typ right now. */
    public function verfuegbar(string $typ, \DateTimeImmutable $now): bool
    {
        return $this->routing->workerFuer($typ, $now) !== null;
    }

    /**
     * "OCR nachholen" (section 6): documents whose OCR was skipped - no
     * worker at the time, or the fallback - go to the worker now, at most
     * $max per call (the admin repeats it). Returns how many were queued.
     */
    public function ocrNachholen(Vault $vault, int $max, \DateTimeImmutable $now): int
    {
        if (!$this->verfuegbar(self::OCR, $now)) {
            return 0;
        }
        $anzahl = 0;
        $nach = 0;
        // A document that cannot go (no readable blob) stays eligible; the
        // cursor walks past it so it does not block the newer ones. At most
        // NACHHOLEN_PRUEFEN candidates per call.
        for ($geprueft = 0; $anzahl < $max && $geprueft < self::NACHHOLEN_PRUEFEN;) {
            $ids = $this->documents->ocrNachholbar($max, $nach);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $documentId) {
                ++$geprueft;
                $nach = $documentId;
                if ($this->einreihen(self::OCR, null, $documentId, $vault, $now) === JobExecutor::Worker && ++$anzahl >= $max) {
                    break;
                }
            }
        }

        return $anzahl;
    }

    /**
     * Whether grants can be made from this session right now (vault
     * unlocked, current generation, no rotation) - false for every document
     * alike, unlike a refusal for one document (issue #88).
     */
    public function freigabeMoeglich(Vault $vault): bool
    {
        return $this->freigaben->sitzungTauglich($vault);
    }

    public function nachholbar(): int
    {
        return $this->documents->zaehleOcrNachholbar();
    }

    private function anWorker(string $typ, int $documentId, Vault $vault, \DateTimeImmutable $now): bool
    {
        if ($this->routing->workerFuer($typ, $now) === null || !$vault->isUnlocked()) {
            return false;
        }
        $item = $this->documents->inboxItem($documentId, Zugriffsbereich::unbeschraenkt());
        if ($item === null) {
            return false;
        }
        try {
            return $this->freigaben->ausSession($item, $vault, $now) > 0;
        } catch (WorkerFreigabeVerweigert) {
            return false;
        }
    }
}
