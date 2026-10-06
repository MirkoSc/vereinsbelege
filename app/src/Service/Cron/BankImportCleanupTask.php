<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\BankImportRepository;
use App\Service\Storage\BlobService;

/**
 * Removes statement imports that stayed a preview (M9-4, issue #62,
 * docs/spec/04-bank-und-abgleich.md section 4): uploaded, never confirmed,
 * never discarded. After KEEP_DAYS the row and its encrypted file go, the
 * same as "Verwerfen" on the import page. A confirmed import is never
 * touched - its file is the original of its bookings.
 *
 * Needs no vault (App\Service\Storage\BlobService::delete() only touches
 * ciphertext and the row), like every cron task (CLAUDE.md section 4).
 */
final readonly class BankImportCleanupTask implements CronTask
{
    public const int KEEP_DAYS = 7;

    /** Bounds one request; a backlog is simply picked up again next run. */
    private const int BATCH = 100;

    public function __construct(
        private BankImportRepository $importe,
        private BlobService $blobs,
    ) {
    }

    public function name(): string
    {
        return 'kontoauszug_vorschauen_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        $entfernt = 0;
        foreach (array_slice($this->importe->previewsBefore($now->modify(sprintf('-%d days', self::KEEP_DAYS))), 0, self::BATCH) as $vorschau) {
            // Confirmed or discarded meanwhile: not ours any more.
            if (!$this->importe->deletePreview($vorschau['id'])) {
                continue;
            }
            $blob = $this->blobs->find($vorschau['file_blob_id']);
            if ($blob !== null) {
                $this->blobs->delete($blob);
            }
            $entfernt++;
        }

        return $entfernt;
    }
}
