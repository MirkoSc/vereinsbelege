<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\DocumentStatus;

/**
 * Which receipts the ZIP export takes, by status (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md section 2: "Status (Default: alle
 * geprüften)").
 *
 * A rejected receipt is never exported. Choosing `Alle` adds the receipts
 * that are not checked yet; they go into `_Wiedervorlage/` (issue #76), so
 * the main tree only ever holds checked ones - and the archive import
 * (M12-3) reads that folder back as "Wiedervorlage".
 */
enum ExportStatus: string
{
    case Geprueft = 'geprueft';
    case Festgeschrieben = 'festgeschrieben';
    case Alle = 'alle';

    /**
     * @return list<DocumentStatus>
     */
    public function statusse(): array
    {
        return match ($this) {
            self::Geprueft => [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben],
            self::Festgeschrieben => [DocumentStatus::Festgeschrieben],
            self::Alle => array_values(array_filter(
                DocumentStatus::cases(),
                static fn(DocumentStatus $s): bool => $s !== DocumentStatus::Abgelehnt,
            )),
        };
    }

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Geprueft => 'Geprüft und festgeschrieben',
            self::Festgeschrieben => 'Nur festgeschrieben',
            self::Alle => 'Alle außer abgelehnte',
        };
    }

    /** Whether a receipt of this status goes into `_Wiedervorlage/`. */
    public static function ungeprueft(DocumentStatus $status): bool
    {
        return $status !== DocumentStatus::Geprueft && $status !== DocumentStatus::Festgeschrieben;
    }
}
