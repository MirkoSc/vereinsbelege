<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The decrypted `bank_account.data_enc` (docs/spec/02-datenmodell.md
 * "Fachdaten"): name and bank connection. IBAN and BIC are stored
 * normalised (upper case, no spaces); a cash box has only a name.
 *
 * The JSON keys are a storage format: renaming one makes existing rows
 * lose that field.
 */
final readonly class BankAccountData
{
    public function __construct(
        public string $name,
        public string $iban = '',
        public string $bic = '',
        public string $bank = '',
    ) {
    }

    /**
     * @return array{name: string, iban: string, bic: string, bank: string}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bank' => $this->bank,
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

        return new self(
            name: $text('name'),
            iban: $text('iban'),
            bic: $text('bic'),
            bank: $text('bank'),
        );
    }
}
