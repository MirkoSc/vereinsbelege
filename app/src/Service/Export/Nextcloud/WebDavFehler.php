<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * A WebDAV call that failed (App\Service\Export\Nextcloud\WebDav). The
 * message is German, for the page, and names no path or file name.
 */
final class WebDavFehler extends \RuntimeException
{
    public static function verbindung(string $grund): self
    {
        return new self('Keine Verbindung zur Nextcloud: ' . $grund);
    }

    public static function status(int $status, string $wobei): self
    {
        return new self(match (true) {
            $status === 401 => 'Die Nextcloud hat die Anmeldung abgelehnt – bitte Benutzer und App-Passwort prüfen.',
            $status === 403 => 'Die Nextcloud verweigert den Zugriff ' . $wobei . ' (HTTP 403) – fehlen Schreibrechte auf den Ordner?',
            $status === 404, $status === 409 => 'Der Ordner wurde in der Nextcloud nicht gefunden (HTTP ' . $status . ') – bitte die Adresse prüfen.',
            $status === 507 => 'Der Speicherplatz in der Nextcloud ist voll (HTTP 507).',
            $status >= 300 && $status < 400 => 'Die Nextcloud leitet um (HTTP ' . $status . ') – bitte die genaue WebDAV-Adresse eintragen.',
            default => 'Die Nextcloud antwortete ' . $wobei . ' mit HTTP ' . $status . '.',
        });
    }

    public static function antwort(string $grund): self
    {
        return new self('Die Antwort der Nextcloud war nicht lesbar: ' . $grund);
    }
}
