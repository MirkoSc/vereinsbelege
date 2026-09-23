<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Repository\SubmissionUploadRepository;
use App\Service\Upload\UploadStore;

/**
 * Finishes a public upload: stores the encrypted blob exactly like the
 * internal chunk upload (App\Service\Upload\UploadStore, issue #24/M4-2), then
 * records which form token it belongs to so App\Service\Submission\
 * SubmissionService can later verify a submitted blob id was really uploaded
 * under the same visit - and so the cron can find it again if nothing ever
 * claims it (App\Service\Cron\SubmissionUploadCleanupTask).
 */
final readonly class SubmissionUploadStore
{
    public function __construct(
        private UploadStore $uploads,
        private SubmissionUploadRepository $submissionUploads,
    ) {
    }

    /**
     * @param iterable<string> $chunks the plaintext, in order and in pieces
     */
    public function store(iterable $chunks, BlobMeta $meta, string $formHash, \DateTimeImmutable $now): Blob
    {
        $blob = $this->uploads->store($chunks, $meta);
        $this->submissionUploads->record($blob->id, $formHash, $now);

        return $blob;
    }
}
