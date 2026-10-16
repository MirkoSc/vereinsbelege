<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where an export run stands (`export_run.status`, M12-4, issue #179,
 * docs/spec/05-auswertung-und-export.md section 2). The values are a
 * storage format.
 *
 *   offen ──(Vollständig gespeichert)──► bestaetigt ──(Zurücksetzen)──► zurueckgesetzt
 *
 * Only `bestaetigt` marks its documents as exported. A run stays `offen`
 * when the download broke off or nobody confirmed it - it marks nothing.
 */
enum ExportRunStatus: string
{
    case Offen = 'offen';
    case Bestaetigt = 'bestaetigt';
    case Zurueckgesetzt = 'zurueckgesetzt';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'Nicht bestätigt',
            self::Bestaetigt => 'Exportiert',
            self::Zurueckgesetzt => 'Zurückgesetzt',
        };
    }
}
