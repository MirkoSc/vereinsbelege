<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `document` (docs/spec/02-datenmodell.md "Fachdaten"). Only the
 * plaintext, structural columns - everything else needs the vault.
 */
final readonly class Document
{
    /**
     * @param list<int> $originalBlobIds page order, as uploaded - the
     *        originals that must survive whatever happens to `pdfBlobId`
     *        (decision E-10)
     */
    public function __construct(
        public int $id,
        public DocumentSource $source,
        public ?int $submissionId,
        public array $originalBlobIds,
        public ?int $pdfBlobId,
        public DocumentStatus $status,
        public OcrStatus $ocrStatus,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $originalBlobIds = json_decode((string) $row['original_blob_ids'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            id: (int) $row['id'],
            source: DocumentSource::from((string) $row['source']),
            submissionId: $row['submission_id'] === null ? null : (int) $row['submission_id'],
            originalBlobIds: is_array($originalBlobIds) ? array_map(intval(...), $originalBlobIds) : [],
            pdfBlobId: $row['pdf_blob_id'] === null ? null : (int) $row['pdf_blob_id'],
            status: DocumentStatus::from((string) $row['status']),
            ocrStatus: OcrStatus::from((string) $row['ocr_status']),
        );
    }
}
