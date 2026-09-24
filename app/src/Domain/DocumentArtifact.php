<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `document_artifact` (docs/spec/02-datenmodell.md "Fachdaten",
 * issue #30/M4-8). `dekSealed`/`dataEnc` stay opaque here - only
 * App\Service\Storage\BlobService and App\Service\Crypto\FieldCipher open
 * them, and `kind: page_image` never uses `dataEnc` at all (the payload is
 * `blobId`'s own ciphertext).
 */
final readonly class DocumentArtifact
{
    public function __construct(
        public int $id,
        public int $documentId,
        public ArtifactKind $kind,
        public int $seq,
        public ?int $blobId,
        public string $dekSealed,
        public ?string $dataEnc,
        public JobExecutor $producer,
        public ?int $jobId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            documentId: (int) $row['document_id'],
            kind: ArtifactKind::from((string) $row['kind']),
            seq: (int) $row['seq'],
            blobId: $row['blob_id'] === null ? null : (int) $row['blob_id'],
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: $row['data_enc'] === null ? null : (string) $row['data_enc'],
            producer: JobExecutor::from((string) $row['producer']),
            jobId: $row['job_id'] === null ? null : (int) $row['job_id'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
