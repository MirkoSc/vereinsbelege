<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The kind of receipt (`invoice.doc_type`, issue #37/M6-3,
 * docs/spec/02-datenmodell.md "Fachdaten"). The AI schema knows a few more
 * (Mahnung, Lieferschein - docs/spec/03-erfassung-und-ki.md section 6);
 * those are not receipts of their own and map to `sonstiges` when M7-5
 * prefills the page.
 */
enum InvoiceType: string
{
    case Rechnung = 'rechnung';
    case Quittung = 'quittung';
    case Gutschrift = 'gutschrift';
    case Kassenbon = 'kassenbon';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Rechnung => 'Rechnung',
            self::Quittung => 'Quittung',
            self::Gutschrift => 'Gutschrift',
            self::Kassenbon => 'Kassenbon',
            self::Sonstiges => 'Sonstiges',
        };
    }
}
