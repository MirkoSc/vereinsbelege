<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\SupplierData;

/**
 * What a confirmed receipt teaches its supplier (issue #201/M7-6b, #245,
 * docs/spec/03-erfassung-und-ki.md section 7): at most one new IBAN, one
 * new alias, a creditor id, VAT id or tax number the supplier had none of
 * and one new mandate reference - each null when there is nothing new. Planned by
 * App\Service\MasterData\LieferantenMerkmale, written by
 * SupplierService::merkmaleErgaenzen().
 *
 * $hinweise say what was left out and why - with values, for the review
 * page only, never for a log, a flash message or the audit. $konflikte:
 * the values left out because another supplier holds them - `felder()` key
 * => that supplier's id (ohne(), issue #245), ids only, for the audit.
 */
final readonly class Merkmale
{
    private const string ZWECK = 'supplier.lernen';

    /**
     * @param list<string> $hinweise
     * @param array<string, int> $konflikte
     */
    public function __construct(
        public ?string $iban = null,
        public ?string $alias = null,
        public ?string $creditorId = null,
        public ?string $mandateRef = null,
        public array $hinweise = [],
        public array $konflikte = [],
        public ?string $vatId = null,
        public ?string $taxNumber = null,
    ) {
    }

    public function leer(): bool
    {
        return $this->iban === null && $this->alias === null && $this->creditorId === null && $this->mandateRef === null
            && $this->vatId === null && $this->taxNumber === null;
    }

    /**
     * The `SupplierData` payload keys that change - for the audit.
     *
     * @return list<string>
     */
    public function felder(): array
    {
        return array_keys(array_filter([
            'aliases' => $this->alias !== null,
            'iban' => $this->iban !== null,
            'creditor_id' => $this->creditorId !== null,
            'mandate_refs' => $this->mandateRef !== null,
            'vat_id' => $this->vatId !== null,
            'tax_number' => $this->taxNumber !== null,
        ]));
    }

    /**
     * The same as German labels, without any value - for a flash message.
     *
     * @return list<string>
     */
    public function bezeichnungen(): array
    {
        return array_values(array_map(self::bezeichnung(...), $this->felder()));
    }

    /**
     * German labels of the values another supplier holds, without any value
     * - for a flash message.
     *
     * @return list<string>
     */
    public function konfliktBezeichnungen(): array
    {
        return array_values(array_map(self::bezeichnung(...), array_keys($this->konflikte)));
    }

    private static function bezeichnung(string $feld): string
    {
        return match ($feld) {
            'aliases' => 'Name als Alias',
            'iban' => 'IBAN',
            'creditor_id' => 'Gläubiger-ID',
            'mandate_refs' => 'Mandatsreferenz',
            'vat_id' => 'USt-ID',
            'tax_number' => 'Steuernummer',
            default => $feld,
        };
    }

    /**
     * Each new value as the page lists it, e.g. "IBAN DE89 3704 …".
     *
     * @return list<string>
     */
    public function beschreibung(): array
    {
        $zeilen = [];
        if ($this->iban !== null) {
            $zeilen[] = 'IBAN ' . trim(chunk_split($this->iban, 4, ' '));
        }
        if ($this->alias !== null) {
            $zeilen[] = 'Name „' . $this->alias . '“ als Alias';
        }
        if ($this->creditorId !== null) {
            $zeilen[] = 'Gläubiger-ID ' . $this->creditorId;
        }
        if ($this->mandateRef !== null) {
            $zeilen[] = 'Mandatsreferenz ' . $this->mandateRef;
        }
        if ($this->vatId !== null) {
            $zeilen[] = 'USt-ID ' . $this->vatId;
        }
        if ($this->taxNumber !== null) {
            $zeilen[] = 'Steuernummer ' . $this->taxNumber;
        }

        return $zeilen;
    }

    /**
     * Without the value of $feld (`felder()` key), with $hinweis added.
     * $anderer: the supplier that holds it already - a conflict (#245);
     * null when it is left out for another reason (the club's own IBAN).
     */
    public function ohne(string $feld, string $hinweis, ?int $anderer = null): self
    {
        return new self(
            iban: $feld === 'iban' ? null : $this->iban,
            alias: $feld === 'aliases' ? null : $this->alias,
            creditorId: $feld === 'creditor_id' ? null : $this->creditorId,
            // A mandate reference only makes sense with its creditor id.
            mandateRef: in_array($feld, ['mandate_refs', 'creditor_id'], true) ? null : $this->mandateRef,
            hinweise: [...$this->hinweise, $hinweis],
            konflikte: $anderer === null ? $this->konflikte : [...$this->konflikte, $feld => $anderer],
            vatId: $feld === 'vat_id' ? null : $this->vatId,
            taxNumber: $feld === 'tax_number' ? null : $this->taxNumber,
        );
    }

    /**
     * What the page showed, keyed by the vault (no plaintext, not
     * guessable): saving learns only if the plan is still the same.
     */
    public function fingerabdruck(\App\Service\Crypto\BlindIndex $index): string
    {
        return bin2hex($index->forValue(self::ZWECK, json_encode(
            [$this->iban, $this->alias, $this->creditorId, $this->mandateRef, $this->vatId, $this->taxNumber],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )));
    }

    /** $daten with the new values added. */
    public function anwenden(SupplierData $daten): SupplierData
    {
        return new SupplierData(
            name: $daten->name,
            aliases: $this->alias === null ? $daten->aliases : [...$daten->aliases, $this->alias],
            address: $daten->address,
            ibans: $this->iban === null ? $daten->ibans : [...$daten->ibans, $this->iban],
            bic: $daten->bic,
            vatId: $this->vatId ?? $daten->vatId,
            taxNumber: $this->taxNumber ?? $daten->taxNumber,
            email: $daten->email,
            website: $daten->website,
            creditorId: $this->creditorId ?? $daten->creditorId,
            mandateRefs: $this->mandateRef === null ? $daten->mandateRefs : [...$daten->mandateRefs, $this->mandateRef],
            customerNumber: $daten->customerNumber,
            notes: $daten->notes,
        );
    }
}
