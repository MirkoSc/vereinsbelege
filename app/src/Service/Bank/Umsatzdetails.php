<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The :86: information to a transaction. Structured (German format: GVC
 * plus "?NN" subfields) or, when a bank sends free text, unstructured -
 * then only $verwendungszweck is filled.
 */
final readonly class Umsatzdetails
{
    /**
     * @param string                $verwendungszweck the SVWZ+ text when the
     *        purpose carries SEPA keys, otherwise the whole joined purpose
     * @param string                $verwendungszweckRoh ?20-?29 and ?60-?63
     *        joined, SEPA keys included
     * @param array<string, string> $sepa SEPA key without "+" => value
     *        ("EREF" => "...", "SVWZ" => "...")
     */
    public function __construct(
        public bool $strukturiert,
        public ?string $gvc,
        public ?string $buchungstext,
        public ?string $primanota,
        public string $verwendungszweck,
        public string $verwendungszweckRoh,
        public array $sepa,
        public ?string $bic,
        public ?string $iban,
        public ?string $name,
        public ?string $textschluesselergaenzung,
    ) {
    }

    public function sepa(string $schluessel): ?string
    {
        return $this->sepa[$schluessel] ?? null;
    }
}
