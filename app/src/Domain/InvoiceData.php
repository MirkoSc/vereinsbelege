<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The vault part of a captured receipt - what `invoice.data_enc` holds as
 * JSON (issue #37/M6-3, docs/spec/02-datenmodell.md "Fachdaten"). Money is
 * integer cents, never float (CLAUDE.md section 5); a tax rate is a decimal
 * string with a dot ("19", "5.5"), as the AI schema has it.
 */
final readonly class InvoiceData
{
    /**
     * @param list<array{rate: string, amount: int}> $taxes
     */
    public function __construct(
        public string $invoiceNumber,
        public int $gross,
        public ?int $net,
        public array $taxes,
        public string $currency,
        public string $purposeShort,
        public string $notes,
    ) {
    }

    /**
     * @return array{invoice_number: string, gross: int, net: int|null, taxes: list<array{rate: string, amount: int}>, currency: string, purpose_short: string, notes: string}
     */
    public function toPayload(): array
    {
        return [
            'invoice_number' => $this->invoiceNumber,
            'gross' => $this->gross,
            'net' => $this->net,
            'taxes' => $this->taxes,
            'currency' => $this->currency,
            'purpose_short' => $this->purposeShort,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $text = static fn(string $feld): string => is_string($payload[$feld] ?? null) ? $payload[$feld] : '';

        $taxes = [];
        foreach (is_array($payload['taxes'] ?? null) ? $payload['taxes'] : [] as $tax) {
            if (is_array($tax) && is_string($tax['rate'] ?? null) && is_int($tax['amount'] ?? null)) {
                $taxes[] = ['rate' => $tax['rate'], 'amount' => $tax['amount']];
            }
        }

        return new self(
            invoiceNumber: $text('invoice_number'),
            gross: is_int($payload['gross'] ?? null) ? $payload['gross'] : 0,
            net: is_int($payload['net'] ?? null) ? $payload['net'] : null,
            taxes: $taxes,
            currency: $text('currency') === '' ? 'EUR' : $text('currency'),
            purposeShort: $text('purpose_short'),
            notes: $text('notes'),
        );
    }
}
