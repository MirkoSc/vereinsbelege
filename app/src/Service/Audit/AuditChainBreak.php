<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Why the audit chain breaks at a row (AuditChain::verify()).
 */
enum AuditChainBreak: string
{
    /** The id does not follow its predecessor: a row missing or moved. */
    case Luecke = 'luecke';
    /** prev_hash is not the predecessor's hash: a row inserted or swapped. */
    case Verkettung = 'verkettung';
    /** The stored fields no longer give the stored hash: a row changed. */
    case Inhalt = 'inhalt';

    public function meldung(): string
    {
        return match ($this) {
            self::Luecke => 'Ein Eintrag fehlt oder die Reihenfolge wurde verändert.',
            self::Verkettung => 'Der Verweis auf den vorherigen Eintrag passt nicht.',
            self::Inhalt => 'Der Inhalt des Eintrags wurde verändert.',
        };
    }
}
