<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why two documents look like the same receipt (issue #40/M6-6,
 * docs/spec/02-datenmodell.md "Statusmodell", `duplikat_verdacht`): both
 * signals are blind indexes, compared in SQL without decrypting anything.
 */
enum DuplikatGrund: string
{
    /** The same original files, byte for byte (`document.content_bi`). */
    case Inhalt = 'inhalt';

    /** The same supplier and invoice number (`invoice.supplier_id` + `number_bi`). */
    case Nummer = 'nummer';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Inhalt => 'gleicher Inhalt',
            self::Nummer => 'gleicher Lieferant und gleiche Rechnungsnummer',
        };
    }
}
