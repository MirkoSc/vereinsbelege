<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One transfer of a payment run (issue #85), decrypted: payee, IBAN, the
 * open amount of its receipts in integer cents (EUR), remittance text and
 * the receipts it settles.
 */
final readonly class ZahllaufUeberweisung
{
    /**
     * @param list<int> $belege invoice ids
     */
    public function __construct(
        public string $endToEndId,
        public string $name,
        public string $iban,
        public int $cent,
        public string $zweck,
        public array $belege,
    ) {
    }

    /**
     * @return array{e2e: string, name: string, iban: string, cent: int, zweck: string, belege: list<int>}
     */
    public function toPayload(): array
    {
        return ['e2e' => $this->endToEndId, 'name' => $this->name, 'iban' => $this->iban, 'cent' => $this->cent, 'zweck' => $this->zweck, 'belege' => $this->belege];
    }

    /**
     * @param array<mixed> $p
     */
    public static function fromPayload(array $p): self
    {
        $text = static fn(string $k): string => is_string($p[$k] ?? null) ? $p[$k] : '';

        return new self(
            $text('e2e'),
            $text('name'),
            $text('iban'),
            is_int($p['cent'] ?? null) ? $p['cent'] : 0,
            $text('zweck'),
            array_values(array_filter(is_array($p['belege'] ?? null) ? $p['belege'] : [], is_int(...))),
        );
    }
}
