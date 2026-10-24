<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Domain\SupplierData;

/**
 * What a confirmed receipt teaches its supplier (issue #201/M7-6b,
 * docs/spec/03-erfassung-und-ki.md section 7): at most one new IBAN, one
 * new alias, a creditor id the supplier had none of and one new mandate
 * reference - each null when there is nothing new. Planned by
 * App\Service\MasterData\LieferantenMerkmale, written by
 * SupplierService::merkmaleErgaenzen().
 *
 * $hinweise say what was left out and why - with values, for the review
 * page only, never for a log, a flash message or the audit.
 */
final readonly class Merkmale
{
    /**
     * @param list<string> $hinweise
     */
    public function __construct(
        public ?string $iban = null,
        public ?string $alias = null,
        public ?string $creditorId = null,
        public ?string $mandateRef = null,
        public array $hinweise = [],
    ) {
    }

    public function leer(): bool
    {
        return $this->iban === null && $this->alias === null && $this->creditorId === null && $this->mandateRef === null;
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
            'ibans' => $this->iban !== null,
            'creditor_id' => $this->creditorId !== null,
            'mandate_refs' => $this->mandateRef !== null,
        ]));
    }

    /**
     * The same as German labels, without any value - for a flash message.
     *
     * @return list<string>
     */
    public function bezeichnungen(): array
    {
        return array_values(array_map(static fn(string $feld): string => match ($feld) {
            'aliases' => 'Name als Alias',
            'ibans' => 'IBAN',
            'creditor_id' => 'Gläubiger-ID',
            'mandate_refs' => 'Mandatsreferenz',
            default => $feld,
        }, $this->felder()));
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

        return $zeilen;
    }

    /** Without the value of $feld (`felder()` key), with $hinweis added - a conflict found later. */
    public function ohne(string $feld, string $hinweis): self
    {
        return new self(
            iban: $feld === 'ibans' ? null : $this->iban,
            alias: $feld === 'aliases' ? null : $this->alias,
            creditorId: $feld === 'creditor_id' ? null : $this->creditorId,
            // A mandate reference only makes sense with its creditor id.
            mandateRef: in_array($feld, ['mandate_refs', 'creditor_id'], true) ? null : $this->mandateRef,
            hinweise: [...$this->hinweise, $hinweis],
        );
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
            vatId: $daten->vatId,
            taxNumber: $daten->taxNumber,
            email: $daten->email,
            website: $daten->website,
            creditorId: $this->creditorId ?? $daten->creditorId,
            mandateRefs: $this->mandateRef === null ? $daten->mandateRefs : [...$daten->mandateRefs, $this->mandateRef],
            customerNumber: $daten->customerNumber,
            notes: $daten->notes,
        );
    }
}
