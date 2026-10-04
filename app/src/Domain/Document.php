<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `document` (docs/spec/02-datenmodell.md "Fachdaten"). The
 * plaintext, structural columns, plus the still sealed DEK and status note
 * (issue #27/M4-5) - opening those needs the vault.
 */
final readonly class Document
{
    /**
     * @param list<int> $originalBlobIds page order, as uploaded - the
     *        originals that must survive whatever happens to `pdfBlobId`
     *        (decision E-10)
     * @param string|null $statusNoteEnc reason of a rejection or note of a
     *        Wiedervorlage, FieldCipher under the row's DEK
     * @param list<int|null> $processedBlobIds parallel to $originalBlobIds:
     *        the scanner's processed version of each page, null where the
     *        page came without one (issue #34/M5-4); empty when no page has
     *        one
     * @param string|null $contentBi blind index of the original files
     *        (issue #40/M6-6, App\Service\Document\Duplikatindex); null
     *        until a session has computed it
     */
    public function __construct(
        public int $id,
        public DocumentSource $source,
        public ?int $submissionId,
        public array $originalBlobIds,
        public ?int $pdfBlobId,
        public DocumentStatus $status,
        public OcrStatus $ocrStatus,
        public ?int $costCenterId = null,
        public ?\DateTimeImmutable $resubmitOn = null,
        public ?string $statusNoteEnc = null,
        public string $dekSealed = '',
        public ?\DateTimeImmutable $createdAt = null,
        public array $processedBlobIds = [],
        public ?string $contentBi = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $originalBlobIds = json_decode((string) $row['original_blob_ids'], true, flags: JSON_THROW_ON_ERROR);
        $originalBlobIds = is_array($originalBlobIds) ? array_values(array_map(intval(...), $originalBlobIds)) : [];

        return new self(
            id: (int) $row['id'],
            source: DocumentSource::from((string) $row['source']),
            submissionId: $row['submission_id'] === null ? null : (int) $row['submission_id'],
            originalBlobIds: $originalBlobIds,
            pdfBlobId: $row['pdf_blob_id'] === null ? null : (int) $row['pdf_blob_id'],
            status: DocumentStatus::from((string) $row['status']),
            ocrStatus: OcrStatus::from((string) $row['ocr_status']),
            costCenterId: ($row['cost_center_id'] ?? null) === null ? null : (int) $row['cost_center_id'],
            resubmitOn: ($row['resubmit_on'] ?? null) === null ? null : new \DateTimeImmutable((string) $row['resubmit_on']),
            statusNoteEnc: ($row['status_note_enc'] ?? null) === null ? null : (string) $row['status_note_enc'],
            dekSealed: (string) ($row['dek_sealed'] ?? ''),
            createdAt: ($row['created_at'] ?? null) === null ? null : new \DateTimeImmutable((string) $row['created_at']),
            processedBlobIds: self::processedAus($row['processed_blob_ids'] ?? null, count($originalBlobIds)),
            contentBi: ($row['content_bi'] ?? null) === null ? null : (string) $row['content_bi'],
        );
    }

    /**
     * The processed version of page $index, or null when it has none.
     */
    public function processedBlobId(int $index): ?int
    {
        return $this->processedBlobIds[$index] ?? null;
    }

    /**
     * Blob ids the document owns and a viewer may therefore fetch: the
     * originals, their processed versions and the working PDF.
     *
     * @return list<int>
     */
    public function blobIds(): array
    {
        return array_values(array_unique([
            ...$this->originalBlobIds,
            ...array_filter($this->processedBlobIds, is_int(...)),
            ...($this->pdfBlobId === null ? [] : [$this->pdfBlobId]),
        ]));
    }

    /**
     * Decodes `processed_blob_ids` to exactly one entry per original page:
     * NULL (every row before migration 016, or no page processed) becomes an
     * empty list, anything but a positive integer becomes null.
     *
     * @return list<int|null>
     */
    private static function processedAus(mixed $spalte, int $seiten): array
    {
        if ($spalte === null) {
            return [];
        }
        $liste = json_decode((string) $spalte, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($liste)) {
            return [];
        }
        $ergebnis = [];
        for ($i = 0; $i < $seiten; $i++) {
            $wert = $liste[$i] ?? null;
            $ergebnis[] = is_int($wert) && $wert > 0 ? $wert : null;
        }

        return $ergebnis;
    }
}
