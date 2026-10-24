<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An artifact upload the worker announced and has not committed yet
 * (issue #52/M7b-2, table `worker_upload`). Structure only: ids, sizes, a
 * checksum of the ciphertext.
 */
final readonly class WorkerUpload
{
    /** Secretstream ciphertext; becomes a file_blob. */
    public const string ART_BLOB = 'blob';

    /** FieldCipher ciphertext; becomes document_artifact.data_enc. */
    public const string ART_DATEN = 'daten';

    public function __construct(
        public int $artifactId,
        public int $workerId,
        public int $jobId,
        public int $attempt,
        public string $art,
        public ?string $header,
        public int $cipherSize,
        public string $cipherSha256,
        public string $uploadId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            artifactId: (int) $row['artifact_id'],
            workerId: (int) $row['worker_id'],
            jobId: (int) $row['job_id'],
            attempt: (int) $row['attempt'],
            art: (string) $row['art'],
            header: $row['header'] === null ? null : (string) $row['header'],
            cipherSize: (int) $row['cipher_size'],
            cipherSha256: (string) $row['cipher_sha256'],
            uploadId: (string) $row['upload_id'],
        );
    }
}
