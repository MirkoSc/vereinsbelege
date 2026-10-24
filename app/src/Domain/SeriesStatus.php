<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a recurring series stands (`recurring_series.status`, issue
 * #57/M8-1): found by detection and waiting for a person, confirmed by one,
 * or dismissed - its receipts stay linked to it, so detection does not
 * propose them again.
 */
enum SeriesStatus: string
{
    case Vorgeschlagen = 'vorgeschlagen';
    case Bestaetigt = 'bestaetigt';
    case Verworfen = 'verworfen';

    public function label(): string
    {
        return match ($this) {
            self::Vorgeschlagen => 'Vorschlag',
            self::Bestaetigt => 'bestätigt',
            self::Verworfen => 'verworfen',
        };
    }
}
