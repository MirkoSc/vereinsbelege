<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One line of the inbox (issue #27/M4-5): the document and what it needs of
 * its submission - the reference number (plaintext) and the still sealed
 * payload. Opening the payload needs the vault
 * (App\Service\Inbox\Posteingang).
 */
final readonly class InboxItem
{
    public function __construct(
        public Document $document,
        public ?string $referenz,
        public ?\DateTimeImmutable $eingegangenAm,
        public ?string $submissionDekSealed,
        public ?string $payloadEnc,
    ) {
    }

    /**
     * @param array<string, mixed> $row a `document` row joined with the
     *        submission columns of DocumentRepository::INBOX_SELECT
     */
    public static function fromRow(array $row): self
    {
        $document = Document::fromRow($row);

        return new self(
            document: $document,
            referenz: ($row['reference_code'] ?? null) === null ? null : (string) $row['reference_code'],
            eingegangenAm: ($row['received_at'] ?? null) === null ? $document->createdAt : new \DateTimeImmutable((string) $row['received_at']),
            submissionDekSealed: ($row['submission_dek_sealed'] ?? null) === null ? null : (string) $row['submission_dek_sealed'],
            payloadEnc: ($row['payload_enc'] ?? null) === null ? null : (string) $row['payload_enc'],
        );
    }
}
