<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The plaintext part of a captured receipt (issue #37/M6-3): the
 * structural `invoice` columns SQL filters and sorts on. Next to it,
 * App\Domain\InvoiceData holds what goes into the vault.
 */
final readonly class InvoiceStructure
{
    public function __construct(
        public InvoiceType $docType,
        public InvoiceDirection $direction,
        public ?int $supplierId,
        public \DateTimeImmutable $invoiceDate,
        public ?\DateTimeImmutable $dueDate,
        public ?\DateTimeImmutable $serviceFrom,
        public ?\DateTimeImmutable $serviceTo,
        public ?int $categoryId,
        public ?int $costCenterId,
    ) {
    }

    public static function aus(InvoiceRecord $record): self
    {
        return new self(
            docType: $record->docType,
            direction: $record->direction,
            supplierId: $record->supplierId,
            invoiceDate: $record->invoiceDate,
            dueDate: $record->dueDate,
            serviceFrom: $record->serviceFrom,
            serviceTo: $record->serviceTo,
            categoryId: $record->categoryId,
            costCenterId: $record->costCenterId,
        );
    }

    /**
     * The column names whose value differs from $vorher - what the audit
     * log records (names only, docs/spec/01-sicherheit.md section 6).
     *
     * @return list<string>
     */
    public function geaendertGegen(?self $vorher): array
    {
        $datum = static fn(?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d');
        $jetzt = $this->spalten($datum);
        if ($vorher === null) {
            return array_keys(array_filter($jetzt, static fn(mixed $wert): bool => $wert !== null));
        }
        $alt = $vorher->spalten($datum);

        return array_keys(array_filter($jetzt, static fn(mixed $wert, string $spalte): bool => $wert !== $alt[$spalte], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param \Closure(?\DateTimeImmutable): ?string $datum
     *
     * @return array<string, int|string|null>
     */
    private function spalten(\Closure $datum): array
    {
        return [
            'doc_type' => $this->docType->value,
            'direction' => $this->direction->value,
            'supplier_id' => $this->supplierId,
            'invoice_date' => $datum($this->invoiceDate),
            'due_date' => $datum($this->dueDate),
            'service_from' => $datum($this->serviceFrom),
            'service_to' => $datum($this->serviceTo),
            'category_id' => $this->categoryId,
            'cost_center_id' => $this->costCenterId,
        ];
    }
}
