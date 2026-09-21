<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `job` row (docs/spec/02-datenmodell.md). The column is a
 * VARCHAR; this enum is the authority for the allowed values.
 */
enum JobStatus: string
{
    case Offen = 'offen';
    case Laeuft = 'laeuft';
    case Fertig = 'fertig';
    case Fehler = 'fehler';
    case Uebersprungen = 'uebersprungen';

    /** Nothing will pick the job up again. */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Fertig, self::Uebersprungen => true,
            default => false,
        };
    }
}
