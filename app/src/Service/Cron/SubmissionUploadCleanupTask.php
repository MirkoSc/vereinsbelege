<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\SubmissionUploadRepository;
use App\Service\Storage\BlobService;

/**
 * Removes blobs the public submission uploaded but nobody's submission ever
 * claimed (issue #24/M4-2, docs/spec/03-erfassung-und-ki.md section 4: same
 * 24 h rule as App\Service\Cron\UploadCleanupTask, which sweeps the *chunks*
 * of an upload that never finished at all - this task sweeps the *finished
 * blob* of a page that was uploaded and then, say, removed again in the
 * browser before the form was ever sent).
 *
 * Deleting a blob needs no vault (App\Service\Storage\BlobService::delete()
 * only touches ciphertext and the row), so this stays a plain cron task like
 * every other one here - it never decrypts (CLAUDE.md section 4).
 */
final readonly class SubmissionUploadCleanupTask implements CronTask
{
    public const int KEEP_HOURS = 24;

    /** Bounds one request; a backlog is simply picked up again next run. */
    private const int BATCH = 200;

    public function __construct(
        private SubmissionUploadRepository $submissionUploads,
        private BlobService $blobs,
    ) {
    }

    public function name(): string
    {
        return 'einreichungs_uploads_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(sprintf('-%d hours', self::KEEP_HOURS));
        $entfernt = 0;

        foreach ($this->submissionUploads->olderThan($cutoff, self::BATCH) as $blobId) {
            $blob = $this->blobs->find($blobId);
            if ($blob === null) {
                continue;
            }

            $this->blobs->delete($blob);
            $entfernt++;
        }

        return $entfernt;
    }
}
