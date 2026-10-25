<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\InboxItem;
use App\Domain\Job;
use App\Domain\Worker;
use App\Domain\WorkerStatus;
use App\Repository\BlobRepository;
use App\Repository\JobRepository;
use App\Repository\VaultRepository;
use App\Repository\WorkerGrantRepository;
use App\Repository\WorkerRepository;
use App\Repository\WorkerUploadRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Inbox\EinreichungsDaten;

/**
 * Hands the worker exactly what one document needs and takes it back
 * afterwards (docs/spec/07-worker.md section 3 "Minimalrechte", issue
 * #52/M7b-2, E-39). The worker never sees the vault private key - only
 * per-blob data keys and the small AI context, each sealed to its W_pub.
 *
 * Two ways in:
 *   - ausSession(): "an den Worker übergeben" - a logged-in session opens
 *     the data keys with the unlocked vault and reseals them to W_pub.
 *     Refused while a vault key rotation runs (issue #90) and with a vault
 *     that is not the current generation.
 *   - beiAnlage()/kontextBeiAnlage(): whoever just generated a data key
 *     (the public submission writes blobs without any secret) seals it to
 *     W_pub as well. Wiring that into the submission belongs to the routing
 *     (issue #53), which decides that a document goes to the worker.
 *
 * And out again: nachJob() after complete/final failure, entkoppelt() when
 * the worker is unpaired, and the cron (WorkerGrantRepository::aufraeumen())
 * as the deadline for everything else.
 */
final readonly class WorkerFreigaben
{
    /** Hard deadline of a grant, whatever happens to its job. */
    public const int GUELTIG_SEKUNDEN = 3 * 86400;

    /** The cron drops grants without a waiting worker job after this. */
    public const int VERWAIST_SEKUNDEN = 900;

    /**
     * @param \Closure(): bool $rotationLaeuft whether a vault key rotation is
     *        in progress (issue #90: no new worker grants from a session then)
     */
    public function __construct(
        private WorkerRepository $workers,
        private WorkerGrantRepository $grants,
        private WorkerUploadRepository $uploads,
        private JobRepository $jobs,
        private BlobRepository $blobs,
        private VaultRepository $vaults,
        private \Closure $rotationLaeuft,
        private WorkerArtefakte $artefakte,
    ) {
    }

    /**
     * Grants the active worker every blob of the document (originals,
     * processed versions, working PDF) plus the AI context of its
     * submission. The caller loaded $item through the user's own access
     * scope - this method grants what that user may read, nothing more.
     *
     * @return int how many blobs were granted
     *
     * @throws WorkerFreigabeVerweigert
     */
    public function ausSession(InboxItem $item, Vault $vault, \DateTimeImmutable $now): int
    {
        $worker = $this->aktiverWorker() ?? throw new WorkerFreigabeVerweigert('kein_worker');
        if (!$vault->isUnlocked()) {
            throw new WorkerFreigabeVerweigert('tresor_gesperrt');
        }
        if (($this->rotationLaeuft)() || $this->vaults->current()?->version !== $vault->version) {
            throw new WorkerFreigabeVerweigert('rotation');
        }

        $document = $item->document;
        $bis = $now->setTimestamp($now->getTimestamp() + self::GUELTIG_SEKUNDEN);
        // Sealed first: if anything about it fails, no grant is written.
        $einreichung = EinreichungsDaten::entschluesseln($item, $vault);
        $kontext = $einreichung !== null && ($einreichung->freitext !== '' || $einreichung->kostenstelleHinweis !== null)
            // Only these two fields - never name, e-mail address or IBAN.
            ? WorkerKrypto::kontextVersiegeln($einreichung->freitext, $einreichung->kostenstelleHinweis, $worker->publicKey)
            : null;
        $anzahl = 0;
        foreach ($document->blobIds() as $blobId) {
            $blob = $this->blobs->find($blobId);
            if ($blob === null || !$blob->isComplete()) {
                continue;
            }
            $this->grants->grantSpeichern(
                $worker->id,
                $document->id,
                $blobId,
                WorkerKrypto::grantVersiegeln($vault->openDataKey($blob->dekSealed), $worker->publicKey),
                $bis,
                $now,
            );
            ++$anzahl;
        }

        if ($kontext !== null) {
            $this->grants->kontextSpeichern($worker->id, $document->id, $kontext, $bis, $now);
        }

        return $anzahl;
    }

    /**
     * Seals a data key that was just generated (no secret needed) to the
     * active worker. False without an active worker - then nothing is
     * granted and the document stays on the browser/session route.
     */
    public function beiAnlage(int $documentId, int $blobId, DataKey $dek, \DateTimeImmutable $now): bool
    {
        $worker = $this->aktiverWorker();
        if ($worker === null) {
            return false;
        }
        $this->grants->grantSpeichern(
            $worker->id,
            $documentId,
            $blobId,
            WorkerKrypto::grantVersiegeln($dek, $worker->publicKey),
            $now->setTimestamp($now->getTimestamp() + self::GUELTIG_SEKUNDEN),
            $now,
        );

        return true;
    }

    /** The AI context at submission time, from the plaintext the form just sent. */
    public function kontextBeiAnlage(int $documentId, string $freitext, ?int $kostenstelleHinweis, \DateTimeImmutable $now): bool
    {
        $worker = $this->aktiverWorker();
        if ($worker === null || ($freitext === '' && $kostenstelleHinweis === null)) {
            return false;
        }
        $this->grants->kontextSpeichern(
            $worker->id,
            $documentId,
            WorkerKrypto::kontextVersiegeln($freitext, $kostenstelleHinweis, $worker->publicKey),
            $now->setTimestamp($now->getTimestamp() + self::GUELTIG_SEKUNDEN),
            $now,
        );

        return true;
    }

    /**
     * After a worker job ended (complete, final failure, out of attempts):
     * its unfinished uploads go, and once no worker job of the document
     * waits or runs any more, so do the grants and the context.
     */
    public function nachJob(Job $job): void
    {
        foreach ($this->uploads->fuerJob($job->id) as $upload) {
            $this->artefakte->verwerfen($upload);
        }
        if ($job->refType === 'document' && $job->refId !== null && !$this->jobs->wartetAufWorker('document', $job->refId)) {
            $this->grants->loescheFuerDokument($job->refId);
        }
    }

    /** The worker was unpaired: nothing sealed to its key stays. */
    public function entkoppelt(int $workerId): void
    {
        foreach ($this->uploads->fuerWorker($workerId) as $upload) {
            $this->artefakte->verwerfen($upload);
        }
        $this->grants->loescheFuerWorker($workerId);
    }

    /** The cron's part: deadline and orphans. */
    public function aufraeumen(\DateTimeImmutable $now): int
    {
        return $this->grants->aufraeumen($now, $now->setTimestamp($now->getTimestamp() - self::VERWAIST_SEKUNDEN))
            + $this->artefakte->aufraeumen($now);
    }

    private function aktiverWorker(): ?Worker
    {
        $worker = $this->workers->aktueller();

        return $worker !== null && $worker->status === WorkerStatus::Aktiv && WorkerProtokoll::unterstuetzt($worker->protokollVersion)
            ? $worker
            : null;
    }
}
