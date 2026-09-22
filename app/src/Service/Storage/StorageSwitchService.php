<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\Blob;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;

/**
 * Switching the blob backend in both directions (M2-5, issue #12;
 * docs/spec/02-datenmodell.md "Dateien", decision E-06).
 *
 * Both backends hold the very same byte stream, so the switch is a copy and
 * not a re-encryption: **no vault is needed anywhere in here**, and nothing
 * is ever decrypted. `file_blob.cipher_sha256` is the checksum every copy is
 * measured against.
 *
 * A shared host gives no long requests (CLAUDE.md section 1), so this is a
 * chain of short ones:
 *
 *   1. `speicher_backend` is set to the target FIRST, so new uploads already
 *      land on the target and the chain only has to carry the existing stock.
 *      Mixed state is harmless - every row names its own backend.
 *   2. `verschieben` moves as many blobs as fit into a time and byte budget;
 *      the caller repeats it while `offen` shrinks.
 *   3. `aufraeumen` removes what a run interrupted between the flip and the
 *      delete left behind.
 *   4. `pruefstart` + `pruefen` walk the table and compare every ciphertext
 *      against its checksum.
 *
 * Nothing but the check cursor is persisted: "still open" is counted from the
 * table. That is why every step may be repeated, and why an interrupted run
 * needs no repair - it is simply continued.
 */
final readonly class StorageSwitchService
{
    /** Progress of the integrity check (ids and counters, no business data). */
    public const string SETTING_CHECK = 'speicher_pruefung';

    /**
     * Time budget of one request. Well below the host's
     * max_execution_time=30 - a blob that started inside the budget still
     * has to finish, and the answer has to reach the browser.
     */
    public const int STEP_SECONDS = 10;

    /** Second budget: many small files are cheap, one 20 MB scan is not. */
    public const int STEP_BYTES = 64 * 1024 * 1024;

    /** Rows per query - the work list is fetched in pieces, like everything. */
    private const int PAGE = 25;

    /** Leftover files cleaned up in one `aufraeumen` request. */
    private const int CLEANUP_LIMIT = 500;

    public function __construct(
        private BlobRepository $repository,
        private BlobService $blobs,
        private SettingRepository $settings,
    ) {
    }

    /** The backend new blobs are written to - and the target of the chain. */
    public function target(): BlobStorage
    {
        return BlobService::configuredStorage($this->settings);
    }

    /**
     * Starts a switch: from here on new uploads go to the target, and the
     * stock becomes the chain's work list. Idempotent - choosing the backend
     * that is already set only clears the old check result.
     */
    public function setTarget(BlobStorage $target): StorageState
    {
        $this->settings->set(BlobService::SETTING_BACKEND, $target->value);
        $this->settings->set(self::SETTING_CHECK, StorageCheck::leer()->toJson());

        return $this->state();
    }

    /** Snapshot for the admin page, without doing any work. */
    public function state(?string $meldung = null): StorageState
    {
        $target = $this->target();
        $inventory = $this->repository->inventory();

        $drafts = 0;
        foreach ($inventory as $entry) {
            $drafts += $entry['entwuerfe'];
        }

        return new StorageState(
            ziel: $target,
            bestand: $inventory,
            offen: $this->repository->countForeign($target),
            gesamt: $this->repository->countComplete(),
            entwuerfe: $drafts,
            pruefung: $this->check(),
            meldung: $meldung,
        );
    }

    /**
     * One request worth of moving. Repeat while `offen` shrinks; a request
     * that moves nothing although blobs are open means every remaining one
     * failed - the caller stops there instead of looping forever.
     */
    public function move(): StorageState
    {
        $target = $this->target();
        $start = hrtime(true);
        $cursor = 0;
        $moved = 0;
        $bytes = 0;
        $failed = [];

        while ($this->withinBudget($start, $bytes)) {
            $batch = $this->repository->findForeign($target, self::PAGE, $cursor);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $blob) {
                $cursor = $blob->id;
                if ($this->moveBlob($blob, $target)) {
                    $moved++;
                    $bytes += $blob->size;
                } else {
                    $failed[] = $blob->id;
                }

                if (!$this->withinBudget($start, $bytes)) {
                    break;
                }
            }
        }

        $open = $this->repository->countForeign($target);
        $message = $failed === []
            ? sprintf('%s verschoben, %d offen.', self::dateien($moved), $open)
            : sprintf(
                '%s verschoben, %d offen, %d nicht verschiebbar.',
                self::dateien($moved),
                $open,
                count($failed),
            );

        return $this->state($message)->mitFortschritt($moved, $bytes, $failed);
    }

    /**
     * Removes what an interrupted run left behind: chunk rows of blobs that
     * live in the file system by now, and files of blobs that live in the
     * database. Both are leftovers, never the only copy - the row always
     * names the shelf its content was verified on.
     */
    public function cleanUp(): StorageState
    {
        $chunks = $this->repository->deleteStrayChunks();

        $files = 0;
        foreach ($this->repository->findStrayFiles(self::CLEANUP_LIMIT) as $blob) {
            $this->blobs->backendFor(BlobStorage::Fs)->delete($blob);
            $this->repository->setFsName($blob->id, null);
            $files++;
        }

        return $this->state(sprintf(
            'Aufgeräumt: %d Reste im Dateisystem, %d in der Datenbank.',
            $files,
            $chunks,
        ));
    }

    /** Begins a new integrity check - also the standalone one on the page. */
    public function startCheck(): StorageState
    {
        $this->store(new StorageCheck(gesamt: $this->repository->countComplete()));

        return $this->state('Integritätsprüfung gestartet.');
    }

    /**
     * One request worth of checking, from the stored cursor on. Needs no
     * vault: it compares bytes, it does not read a receipt.
     */
    public function verify(): StorageState
    {
        $check = $this->check();
        if ($check->fertig) {
            return $this->state('Integritätsprüfung abgeschlossen.');
        }

        $start = hrtime(true);
        $cursor = $check->letzteId;
        $checked = 0;
        $damaged = [];
        $done = false;

        while ($this->withinBudget($start, 0)) {
            $batch = $this->repository->findCompleteAfter($cursor, self::PAGE);
            if ($batch === []) {
                $done = true;
                break;
            }

            foreach ($batch as $blob) {
                $cursor = $blob->id;
                $checked++;
                if (!$this->blobs->verify($blob)) {
                    $damaged[] = $blob->id;
                }

                if (!$this->withinBudget($start, 0)) {
                    break;
                }
            }
        }

        $check = $check->mitFortschritt($cursor, $checked, $damaged);
        if ($done) {
            $check = $check->abgeschlossen(new \DateTimeImmutable());
        }
        $this->store($check);

        return $this->state($done
            ? sprintf(
                'Integritätsprüfung abgeschlossen: %s geprüft, %d beschädigt.',
                self::dateien($check->geprueft),
                count($check->beschaedigt),
            )
            : sprintf(
                '%d von %d Dateien geprüft.',
                $check->geprueft,
                max($check->gesamt, $check->geprueft),
            ));
    }

    public function check(): StorageCheck
    {
        return StorageCheck::fromJson($this->settings->get(self::SETTING_CHECK));
    }

    /**
     * Moves one blob and returns whether it arrived intact.
     *
     * The order is the whole safety of the chain: the file name is in the row
     * before the copy exists (so a leftover is findable), the row is flipped
     * only after the copy matched the checksum, and the source is deleted
     * only after the flip. Every interruption leaves a leftover at worst,
     * never a blob whose row points at content that is not there.
     */
    private function moveBlob(Blob $blob, BlobStorage $target): bool
    {
        $source = $blob->storage;
        if ($source === $target || !$blob->isComplete()) {
            return false;
        }

        try {
            if ($target === BlobStorage::Fs && $blob->fsName === null) {
                $this->repository->setFsName($blob->id, FsBlobBackend::newName());
                $blob = $this->repository->find($blob->id)
                    ?? throw new BlobException('Blob row disappeared.');
            }
            if ($target === BlobStorage::Db) {
                // Chunks of an attempt that broke off; the seq numbering
                // starts at 0 again.
                $this->repository->deleteChunks($blob->id);
            }

            if (!$this->copy($blob, $source, $target)) {
                $this->blobs->backendFor($target)->delete($blob);

                return false;
            }
        } catch (BlobException) {
            // A source that cannot be read or a target that cannot be
            // written: the row stays where it is, the id is reported, and
            // the chain moves on to the next blob.
            return false;
        }

        $this->repository->setStorage($blob->id, $target);
        $this->blobs->backendFor($source)->delete($blob);
        if ($target === BlobStorage::Db) {
            $this->repository->setFsName($blob->id, null);
        }

        return true;
    }

    /** Streams the ciphertext across and compares it to `cipher_sha256`. */
    private function copy(Blob $blob, BlobStorage $source, BlobStorage $target): bool
    {
        $sink = $this->blobs->backendFor($target)->open($blob);
        $hash = hash_init('sha256');

        try {
            foreach ($this->blobs->backendFor($source)->read($blob) as $piece) {
                hash_update($hash, $piece);
                $sink->write($piece);
            }
            $sink->commit();
        } catch (\Throwable $e) {
            $sink->discard();

            throw $e;
        }

        return hash_equals((string) $blob->cipherSha256, hash_final($hash, true));
    }

    /** German counts the singular differently; the page says so correctly. */
    private static function dateien(int $count): string
    {
        return $count === 1 ? '1 Datei' : $count . ' Dateien';
    }

    private function withinBudget(int $start, int $bytes): bool
    {
        return $bytes < self::STEP_BYTES
            && (hrtime(true) - $start) < self::STEP_SECONDS * 1_000_000_000;
    }

    private function store(StorageCheck $check): void
    {
        $this->settings->set(self::SETTING_CHECK, $check->toJson());
    }
}
