<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

use App\Domain\InvoiceType;

/**
 * The fields of one structured e-invoice (docs/spec/03-erfassung-und-ki.md
 * section 3: "Lieferant, USt-ID, IBAN, Rechnungsnr., Beträge, Steuern"),
 * read by ERechnungLeser - exactly what the XML says, nothing inferred.
 * Amounts in integer cents (CLAUDE.md section 5), dates as `Y-m-d`, empty
 * strings for what the invoice does not carry.
 *
 * alsExtraktion() gives it the shape of the AI's answer (prompt version
 * `extract-v1`, section 6), so the review page fills its form from either
 * the same way, and the AI (M7-5) only has category and purpose left to do.
 */
final readonly class ERechnung
{
    /**
     * @param list<array{rate: string, amount: int}> $steuern rate in percent as App\Service\Processing\Betrag::prozent() writes it
     * @param list<string> $warnungen German sentences for the review page, without content
     */
    public function __construct(
        public ERechnungSyntax $syntax,
        public string $profil,
        public InvoiceType $typ,
        public string $nummer,
        public string $datum,
        public ?string $faellig,
        public ?string $leistungVon,
        public ?string $leistungBis,
        public string $waehrung,
        public int $brutto,
        public ?int $netto,
        public array $steuern,
        public string $lieferantName,
        public string $lieferantAnschrift,
        public string $ustId,
        public string $steuernummer,
        public string $email,
        public string $iban,
        public string $bic,
        public string $glaeubigerId,
        public string $mandatsreferenz,
        public string $kundennummer,
        public string $vertragsnummer,
        public string $zahlart,
        public bool $bezahlt,
        public array $warnungen,
    ) {
    }

    /**
     * The `extract-v1` answer this invoice amounts to: every key of the
     * schema, the ones an e-invoice cannot know (category, purpose, sphere,
     * cost center, recurrence) empty, confidence 1.0 for what was read.
     *
     * @return array<string, mixed>
     */
    public function alsExtraktion(): array
    {
        return [
            'document_type' => $this->typ->value,
            // The club received this invoice: an expense (a credit note
            // too - it is still the supplier's document).
            'direction' => 'ausgabe',
            'supplier' => [
                'name' => $this->lieferantName,
                'address' => $this->lieferantAnschrift,
                'iban' => $this->iban,
                'bic' => $this->bic,
                'vat_id' => $this->ustId,
                'tax_number' => $this->steuernummer,
                'email' => $this->email,
                'website' => '',
                'creditor_id' => $this->glaeubigerId,
            ],
            'invoice_number' => $this->nummer,
            'customer_number' => $this->kundennummer,
            'contract_number' => $this->vertragsnummer,
            'invoice_date' => $this->datum,
            'due_date' => $this->faellig,
            'service_period' => ['from' => $this->leistungVon, 'to' => $this->leistungBis],
            'currency' => $this->waehrung,
            'total_gross' => self::dezimal($this->brutto),
            'total_net' => $this->netto === null ? null : self::dezimal($this->netto),
            'taxes' => array_map(
                static fn(array $steuer): array => ['rate' => $steuer['rate'], 'amount' => self::dezimal($steuer['amount'])],
                $this->steuern,
            ),
            'payment' => [
                'already_paid' => $this->bezahlt,
                'method' => $this->zahlart,
                'mandate_reference' => $this->mandatsreferenz,
            ],
            'purpose_short' => '',
            'category_id' => null,
            'sphere' => null,
            'cost_center_id' => null,
            'recurring' => ['likely' => false, 'period' => null, 'evidence' => ''],
            'confidence' => [
                'supplier' => $this->lieferantName === '' ? 0.0 : 1.0,
                'total_gross' => 1.0,
                'invoice_date' => 1.0,
                'category' => 0.0,
            ],
            'warnings' => $this->warnungen,
        ];
    }

    /** Cents as the schema's decimal string: 12345 -> "123.45", -5 -> "-0.05". */
    public static function dezimal(int $cent): string
    {
        $betrag = abs($cent);

        return ($cent < 0 ? '-' : '') . intdiv($betrag, 100) . '.' . str_pad((string) ($betrag % 100), 2, '0', STR_PAD_LEFT);
    }
}
