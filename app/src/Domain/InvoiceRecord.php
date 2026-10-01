<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One `invoice` row as stored (issue #37/M6-3): the plaintext structure plus
 * the sealed data key and the ciphertext. What App\Repository\
 * InvoiceRepository returns; App\Service\Invoice\Pruefung opens `dataEnc`
 * once a vault is at hand.
 */
final readonly class InvoiceRecord
{
    public function __construct(
        public int $id,
        public int $documentId,
        public InvoiceType $docType,
        public InvoiceDirection $direction,
        public ?int $supplierId,
        public \DateTimeImmutable $invoiceDate,
        public ?\DateTimeImmutable $dueDate,
        public ?\DateTimeImmutable $serviceFrom,
        public ?\DateTimeImmutable $serviceTo,
        public ?int $categoryId,
        public ?int $costCenterId,
        public ?int $checkedBy,
        public ?\DateTimeImmutable $checkedAt,
        public ?int $lockedBy,
        public ?\DateTimeImmutable $lockedAt,
        public string $dekSealed,
        public string $dataEnc,
        public ?string $numberBi,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $datum = static fn(mixed $wert): ?\DateTimeImmutable => $wert === null ? null : new \DateTimeImmutable((string) $wert);
        $id = static fn(mixed $wert): ?int => $wert === null ? null : (int) $wert;

        return new self(
            id: (int) $row['id'],
            documentId: (int) $row['document_id'],
            docType: InvoiceType::from((string) $row['doc_type']),
            direction: InvoiceDirection::from((string) $row['direction']),
            supplierId: $id($row['supplier_id']),
            invoiceDate: new \DateTimeImmutable((string) $row['invoice_date']),
            dueDate: $datum($row['due_date']),
            serviceFrom: $datum($row['service_from']),
            serviceTo: $datum($row['service_to']),
            categoryId: $id($row['category_id']),
            costCenterId: $id($row['cost_center_id']),
            checkedBy: $id($row['checked_by']),
            checkedAt: $datum($row['checked_at']),
            lockedBy: $id($row['locked_by']),
            lockedAt: $datum($row['locked_at']),
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            numberBi: $row['number_bi'] === null ? null : (string) $row['number_bi'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
