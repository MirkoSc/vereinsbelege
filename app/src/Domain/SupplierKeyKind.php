<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The kinds of blind index kept per supplier in `supplier_key` (M6-2, issue
 * #36, docs/spec/02-datenmodell.md "Lieferanten"): what the automatic
 * resolution (docs/spec/03-erfassung-und-ki.md section 7) looks up.
 *
 * The value is what `supplier_key.kind` stores and, prefixed with
 * "supplier.", the blind index purpose - never renamed once shipped, or
 * every stored index stops matching.
 */
enum SupplierKeyKind: string
{
    case Name = 'name';
    case Iban = 'iban';
    case VatId = 'vat_id';
    case TaxNumber = 'tax_number';
    case CreditorId = 'creditor_id';
    case Mandate = 'mandate';

    /** Purpose of the blind index (App\Service\Crypto\BlindIndex). */
    public function purpose(): string
    {
        return 'supplier.' . $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Name',
            self::Iban => 'IBAN',
            self::VatId => 'USt-ID',
            self::TaxNumber => 'Steuernummer',
            self::CreditorId => 'Gläubiger-ID',
            self::Mandate => 'Mandatsreferenz',
        };
    }

    /**
     * Whether a value of this kind identifies exactly one supplier: a
     * second supplier with the same IBAN, VAT id, tax number or creditor id
     * is a duplicate to be merged, not a new one. Names repeat (two
     * "Müller"), and a mandate reference is only unique per creditor.
     */
    public function eindeutig(): bool
    {
        return match ($this) {
            self::Iban, self::VatId, self::TaxNumber, self::CreditorId => true,
            self::Name, self::Mandate => false,
        };
    }
}
