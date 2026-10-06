<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * No HTTP answer from the provider: name resolution, connection, TLS or
 * timeout. The message is German and meant for the admin; it never carries
 * the API key (curl's own error texts name host and cause, not headers).
 */
final class KiVerbindungsfehler extends \RuntimeException
{
    public static function zeitueberschreitung(int $timeoutS): self
    {
        return new self(sprintf('Keine Antwort innerhalb von %d Sekunden (Zeitüberschreitung).', $timeoutS));
    }

    public static function verbindung(string $ursache): self
    {
        return new self('Verbindung fehlgeschlagen: ' . ($ursache !== '' ? $ursache : 'unbekannte Ursache') . '.');
    }
}
