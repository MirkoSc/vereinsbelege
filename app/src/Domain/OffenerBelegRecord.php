<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the open-payment lists (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten"): the receipt as stored,
 * the submission columns joined to it (for "Erstattungen je Einreicher")
 * and whether it was captured before the matching existed (Migration 037,
 * E-37). Ciphertext plus plaintext structure - nothing here is readable
 * without the vault.
 */
final readonly class OffenerBelegRecord
{
    public function __construct(
        public InvoiceRecord $record,
        public ?int $submissionId,
        public ?string $submissionDekSealed,
        public ?string $submissionPayloadEnc,
        public bool $altbestand,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            InvoiceRecord::fromRow($row),
            $row['submission_id'] === null ? null : (int) $row['submission_id'],
            $row['submission_dek_sealed'] === null ? null : (string) $row['submission_dek_sealed'],
            $row['submission_payload_enc'] === null ? null : (string) $row['submission_payload_enc'],
            (int) $row['altbestand'] === 1,
        );
    }
}
