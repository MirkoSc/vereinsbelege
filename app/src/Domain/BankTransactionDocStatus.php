<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a booking has its receipt (`bank_transaction.doc_status`, M9-4,
 * issue #62, docs/spec/02-datenmodell.md "Fachdaten"). The values are a
 * storage format. `zugeordnet` is derived from allocations once the
 * matching exists (M10); an import only ever writes `fehlt` or
 * `nicht_noetig`.
 */
enum BankTransactionDocStatus: string
{
    case Fehlt = 'fehlt';
    case Zugeordnet = 'zugeordnet';
    case NichtNoetig = 'nicht_noetig';

    public static function fuerNeueBuchung(bool $belegNoetig): self
    {
        return $belegNoetig ? self::Fehlt : self::NichtNoetig;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fehlt => 'Beleg fehlt',
            self::Zugeordnet => 'Beleg zugeordnet',
            self::NichtNoetig => 'kein Beleg nötig',
        };
    }
}
