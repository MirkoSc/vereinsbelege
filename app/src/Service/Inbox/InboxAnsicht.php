<?php

declare(strict_types=1);

namespace App\Service\Inbox;

use App\Domain\DocumentStatus;

/**
 * The status groups the inbox list offers (issue #27/M4-5). `Offen` is
 * what still needs a decision: new documents and every Wiedervorlage whose
 * date has come.
 */
enum InboxAnsicht: string
{
    case Offen = 'offen';
    case Wiedervorlage = 'wiedervorlage';
    case Angenommen = 'angenommen';
    case Abgelehnt = 'abgelehnt';
    case Alle = 'alle';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Offen => 'Offen',
            self::Wiedervorlage => 'Wiedervorlage',
            self::Angenommen => 'Angenommen',
            self::Abgelehnt => 'Abgelehnt',
            self::Alle => 'Alle',
        };
    }

    /**
     * Statuses past the inbox: accepted and on their way through the
     * pipeline.
     *
     * @return list<DocumentStatus>
     */
    public static function angenommeneStatus(): array
    {
        return [
            DocumentStatus::BereitZurAuswertung,
            DocumentStatus::Ausgewertet,
            DocumentStatus::InPruefung,
            DocumentStatus::Geprueft,
            DocumentStatus::Festgeschrieben,
            DocumentStatus::KiFehler,
        ];
    }
}
