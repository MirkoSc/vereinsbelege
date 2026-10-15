<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where an archive import stands (`archive_import.status`, M12-3, issue
 * #77, docs/spec/05-auswertung-und-export.md section 3). The values are a
 * storage format.
 *
 *   vorschau ──(Import starten)──► laeuft ──(last step)──► fertig
 *       └──────────(Verwerfen, upload expired)──────────► abgebrochen
 *
 * `abgebrochen` keeps the documents written so far - they are ordinary
 * receipts by then.
 */
enum ArchiveImportStatus: string
{
    /** Uploaded and readable; nothing written yet. */
    case Vorschau = 'vorschau';

    /** Confirmed; the step chain writes one document per file. */
    case Laeuft = 'laeuft';

    case Fertig = 'fertig';

    case Abgebrochen = 'abgebrochen';

    public function label(): string
    {
        return match ($this) {
            self::Vorschau => 'Vorschau',
            self::Laeuft => 'Wird übernommen',
            self::Fertig => 'Übernommen',
            self::Abgebrochen => 'Abgebrochen',
        };
    }

    /** Still holds its upload chunks. */
    public function offen(): bool
    {
        return $this === self::Vorschau || $this === self::Laeuft;
    }
}
