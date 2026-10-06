<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a statement import stands (`bank_import.status`, M9-4, issue #62,
 * docs/spec/04-bank-und-abgleich.md section 4). The values are a storage
 * format.
 *
 *   vorschau ──(Übernehmen)──► laeuft ──(last step)──► fertig
 *       └──(Verwerfen, or the cron after a week)──► row and file deleted
 */
enum BankImportStatus: string
{
    /** Uploaded and readable; nothing written yet. */
    case Vorschau = 'vorschau';

    /** Confirmed; the step chain writes the bookings. */
    case Laeuft = 'laeuft';

    case Fertig = 'fertig';

    public function label(): string
    {
        return match ($this) {
            self::Vorschau => 'Vorschau',
            self::Laeuft => 'Wird übernommen',
            self::Fertig => 'Übernommen',
        };
    }
}
