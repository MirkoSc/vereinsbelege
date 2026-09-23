<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a submitter wants done about the money (docs/spec/03-erfassung-und-ki.md
 * section 1, issue #24/M4-2): part of `submission.payload_enc`, so this enum
 * only shapes the JSON inside the vault, never a plaintext column.
 */
enum Erstattungsart: string
{
    case Ueberweisung = 'ueberweisung';
    case Bar = 'bar';
    case Keine = 'keine';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Ueberweisung => 'Bitte an mich überweisen',
            self::Bar => 'Bar erhalten',
            self::Keine => 'Keine Erstattung – bereits vom Verein bezahlt',
        };
    }
}
