<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The decrypted `supplier.data_enc` (docs/spec/02-datenmodell.md
 * "Fachdaten"): everything about a supplier that is club data. Values are
 * stored as they were validated - IBANs, BIC, VAT and creditor id already
 * normalised (upper case, no spaces), the rest trimmed.
 *
 * The JSON keys are a storage format: renaming one makes existing rows
 * lose that field.
 */
final readonly class SupplierData
{
    /**
     * @param list<string> $aliases
     * @param list<string> $ibans
     * @param list<string> $mandateRefs
     */
    public function __construct(
        public string $name,
        public array $aliases = [],
        public string $address = '',
        public array $ibans = [],
        public string $bic = '',
        public string $vatId = '',
        public string $taxNumber = '',
        public string $email = '',
        public string $website = '',
        public string $creditorId = '',
        public array $mandateRefs = [],
        public string $customerNumber = '',
        public string $notes = '',
    ) {
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'aliases' => $this->aliases,
            'address' => $this->address,
            'iban' => $this->ibans,
            'bic' => $this->bic,
            'vat_id' => $this->vatId,
            'tax_number' => $this->taxNumber,
            'email' => $this->email,
            'website' => $this->website,
            'creditor_id' => $this->creditorId,
            'mandate_refs' => $this->mandateRefs,
            'customer_number' => $this->customerNumber,
            'notes' => $this->notes,
        ];
    }

    /**
     * Tolerant of missing keys: a field added later is simply empty in an
     * older row.
     *
     * @param array<mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $text = static fn(string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $liste = static fn(string $key): array => is_array($payload[$key] ?? null)
            ? array_values(array_filter($payload[$key], is_string(...)))
            : [];

        return new self(
            name: $text('name'),
            aliases: $liste('aliases'),
            address: $text('address'),
            ibans: $liste('iban'),
            bic: $text('bic'),
            vatId: $text('vat_id'),
            taxNumber: $text('tax_number'),
            email: $text('email'),
            website: $text('website'),
            creditorId: $text('creditor_id'),
            mandateRefs: $liste('mandate_refs'),
            customerNumber: $text('customer_number'),
            notes: $text('notes'),
        );
    }
}
