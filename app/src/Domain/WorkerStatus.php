<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `worker` row (docs/spec/07-worker.md section 4, issue
 * #51/M7b-1). The column is a VARCHAR; this enum is the authority.
 */
enum WorkerStatus: string
{
    /** Paired, but the admin has not confirmed the key fingerprint yet. */
    case Gekoppelt = 'gekoppelt';
    case Aktiv = 'aktiv';
    /** Unpaired: its access key is gone, every request is refused. */
    case Gesperrt = 'gesperrt';

    public function label(): string
    {
        return match ($this) {
            self::Gekoppelt => 'Wartet auf Bestätigung',
            self::Aktiv => 'Aktiv',
            self::Gesperrt => 'Entkoppelt',
        };
    }
}
