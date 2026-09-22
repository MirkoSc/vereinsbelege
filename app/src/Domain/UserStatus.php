<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `user` row (docs/spec/02-datenmodell.md). The column is a
 * VARCHAR; this enum is the authority for the allowed values, the same
 * pattern as JobStatus/MailStatus.
 */
enum UserStatus: string
{
    case Eingeladen = 'eingeladen';
    case Aktiv = 'aktiv';
    case Gesperrt = 'gesperrt';
}
