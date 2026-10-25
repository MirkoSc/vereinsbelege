<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * `payment_run.status` (issue #85, migrations/042_payment_run.sql, E-42).
 * Whether a run is settled is not a status of its own: it is derived from
 * its receipts once the matching has found the bookings
 * (App\Service\Bank\Zahllauf::ausgeglichen()).
 */
enum ZahllaufStatus: string
{
    /** The file was created for the bank; its receipts cannot be ordered again. */
    case Angewiesen = 'angewiesen';
    /** Taken back (the bank refused or never got the file): its receipts are free again. */
    case Verworfen = 'verworfen';

    public function label(): string
    {
        return match ($this) {
            self::Angewiesen => 'angewiesen',
            self::Verworfen => 'verworfen',
        };
    }
}
