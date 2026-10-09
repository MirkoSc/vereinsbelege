<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\DocumentStatus;
use App\Domain\InvoiceRecord;

/**
 * One receipt as the ZIP export reads it (issue #76/M12-2): the `invoice`
 * row - still ciphertext - plus what the export needs of its `document`:
 * the status and the files. What App\Repository\InvoiceRepository::
 * exportListe() returns.
 */
final readonly class ExportBeleg
{
    /**
     * @param list<int> $originalBlobIds page order, as uploaded
     */
    public function __construct(
        public InvoiceRecord $invoice,
        public DocumentStatus $status,
        public ?int $pdfBlobId,
        public array $originalBlobIds,
    ) {
    }

    /**
     * @param array<string, mixed> $row an `invoice` row plus `document_status`,
     *        `document_pdf_blob_id` and `document_original_blob_ids`
     */
    public static function fromRow(array $row): self
    {
        $originale = json_decode((string) $row['document_original_blob_ids'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            invoice: InvoiceRecord::fromRow($row),
            status: DocumentStatus::from((string) $row['document_status']),
            pdfBlobId: $row['document_pdf_blob_id'] === null ? null : (int) $row['document_pdf_blob_id'],
            originalBlobIds: is_array($originale) ? array_values(array_map(intval(...), $originale)) : [],
        );
    }
}
