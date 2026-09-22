<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `mail_queue` row (docs/spec/02-datenmodell.md). The column is
 * a VARCHAR; this enum is the authority for the allowed values, the same
 * pattern as JobStatus for `job`.
 */
enum MailStatus: string
{
    case Offen = 'offen';
    case Laeuft = 'laeuft';
    case Gesendet = 'gesendet';
    case Fehler = 'fehler';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Offen => 'Wartet',
            self::Laeuft => 'Wird versendet',
            self::Gesendet => 'Gesendet',
            self::Fehler => 'Fehlgeschlagen',
        };
    }

    /** Variant of the .marke component in public/css/app.css. */
    public function markeKlasse(): string
    {
        return match ($this) {
            self::Gesendet => 'marke-ok',
            self::Fehler => 'marke-fehler',
            default => 'marke-warnung',
        };
    }
}
