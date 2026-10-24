<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The vault part of a recurring series - what `recurring_series.data_enc`
 * holds as JSON (issue #57/M8-1, docs/spec/02-datenmodell.md "Fachdaten").
 * The expected amount is integer cents, never float (CLAUDE.md section 5).
 */
final readonly class SeriesData
{
    public function __construct(
        public string $label,
        public int $expectedGross,
        public string $currency,
        public string $contractRef,
    ) {
    }

    /**
     * @return array{label: string, expected_gross: int, currency: string, contract_ref: string}
     */
    public function toPayload(): array
    {
        return [
            'label' => $this->label,
            'expected_gross' => $this->expectedGross,
            'currency' => $this->currency,
            'contract_ref' => $this->contractRef,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $text = static fn(string $feld): string => is_string($payload[$feld] ?? null) ? $payload[$feld] : '';

        return new self(
            label: $text('label'),
            expectedGross: is_int($payload['expected_gross'] ?? null) ? $payload['expected_gross'] : 0,
            currency: $text('currency') === '' ? 'EUR' : $text('currency'),
            contractRef: $text('contract_ref'),
        );
    }
}
