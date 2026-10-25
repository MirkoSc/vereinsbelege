<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\ImapSicherheit;
use App\Repository\SettingRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * Stores the mail intake's settings (issue #87, E-54) in `setting`, the
 * same way App\Service\Mail\MailSettingsRepository keeps the SMTP password:
 *
 * - `mail_eingang` - JSON of the plain operating data (server, port,
 *   encryption, user, folders, interval, switch).
 * - `mail_eingang_geheim_enc` - base64 of ServerCrypto::encrypt() over
 *   `{passwort, absender[]}`: the password and the sender allowlist
 *   (addresses of members and suppliers) never lie in the database in
 *   the clear.
 * - `mail_eingang_status` - what the worker last reported about its
 *   mailbox run: time, error code, counters. No content.
 *
 * Read without a session (heartbeat) - hence the server key, not the vault.
 */
final readonly class MailEingangSpeicher
{
    public const string SETTING = 'mail_eingang';

    public const string SETTING_GEHEIM = 'mail_eingang_geheim_enc';

    public const string SETTING_STATUS = 'mail_eingang_status';

    public function __construct(
        private SettingRepository $settings,
        private ServerCrypto $crypto,
    ) {
    }

    public function laden(): MailEingangEinstellungen
    {
        $daten = json_decode($this->settings->get(self::SETTING), true);
        if (!is_array($daten)) {
            return new MailEingangEinstellungen();
        }
        $geheim = [];
        $roh = base64_decode($this->settings->get(self::SETTING_GEHEIM), true);
        if (is_string($roh) && $roh !== '') {
            // A value from another server key reads as "not set": the
            // admin enters the password again (like the SMTP password).
            $json = $this->crypto->decryptOrNull($roh);
            $geheim = $json === null ? [] : (json_decode($json, true) ?: []);
            if ($json !== null) {
                sodium_memzero($json);
            }
        }
        $text = static fn(string $feld): string => is_string($daten[$feld] ?? null) ? $daten[$feld] : '';
        $passwort = is_array($geheim) && is_string($geheim['passwort'] ?? null) ? $geheim['passwort'] : '';
        $minuten = is_int($daten['abruf_minuten'] ?? null) ? $daten['abruf_minuten'] : MailEingangEinstellungen::ABRUF_MINUTEN_STANDARD;

        return new MailEingangEinstellungen(
            aktiv: ($daten['aktiv'] ?? false) === true,
            host: $text('host'),
            port: is_int($daten['port'] ?? null) ? $daten['port'] : 993,
            sicherheit: ImapSicherheit::tryFrom($text('sicherheit')) ?? ImapSicherheit::Ssl,
            benutzer: $text('benutzer'),
            passwort: $passwort,
            ordner: $text('ordner') === '' ? MailEingangEinstellungen::ORDNER_STANDARD : $text('ordner'),
            zielOrdner: $text('ziel_ordner'),
            absender: MailAbsenderListe::ausListe(is_array($geheim) && is_array($geheim['absender'] ?? null) ? $geheim['absender'] : []),
            abrufMinuten: max(MailEingangEinstellungen::ABRUF_MINUTEN_MIN, min(MailEingangEinstellungen::ABRUF_MINUTEN_MAX, $minuten)),
        );
    }

    public function speichern(MailEingangEinstellungen $e): void
    {
        $this->settings->set(self::SETTING, json_encode([
            'aktiv' => $e->aktiv,
            'host' => $e->host,
            'port' => $e->port,
            'sicherheit' => $e->sicherheit->value,
            'benutzer' => $e->benutzer,
            'ordner' => $e->ordner,
            'ziel_ordner' => $e->zielOrdner,
            'abruf_minuten' => $e->abrufMinuten,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $geheim = json_encode(['passwort' => $e->passwort, 'absender' => $e->absender->eintraege], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->settings->set(self::SETTING_GEHEIM, base64_encode($this->crypto->encrypt($geheim)));
        sodium_memzero($geheim);
    }

    /** Whether the module may hand the IMAP access out at all. */
    public function aktiv(): bool
    {
        $daten = json_decode($this->settings->get(self::SETTING), true);

        return is_array($daten) && ($daten['aktiv'] ?? false) === true;
    }

    /**
     * Stores the worker's report of its last mailbox run (heartbeat field
     * `mail_status`). Only a time, an identifier and counters pass.
     *
     * @param array<mixed> $status
     */
    public function statusMerken(array $status): void
    {
        $zeit = $status['zeit'] ?? null;
        $fehler = $status['fehler'] ?? null;
        if (!is_int($zeit) || $zeit < 1) {
            return;
        }
        $sauber = [
            'zeit' => $zeit,
            'fehler' => is_string($fehler) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fehler) === 1 ? $fehler : null,
            'eingereicht' => is_int($status['eingereicht'] ?? null) ? max(0, $status['eingereicht']) : 0,
            'abgelehnt' => is_int($status['abgelehnt'] ?? null) ? max(0, $status['abgelehnt']) : 0,
        ];
        $json = json_encode($sauber, JSON_THROW_ON_ERROR);
        if ($json !== $this->settings->get(self::SETTING_STATUS)) {
            $this->settings->set(self::SETTING_STATUS, $json);
        }
    }

    /** @return ?array{zeit: \DateTimeImmutable, fehler: ?string, eingereicht: int, abgelehnt: int} */
    public function status(): ?array
    {
        $daten = json_decode($this->settings->get(self::SETTING_STATUS), true);
        if (!is_array($daten) || !is_int($daten['zeit'] ?? null)) {
            return null;
        }

        return [
            'zeit' => new \DateTimeImmutable('@' . $daten['zeit'])->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            'fehler' => is_string($daten['fehler'] ?? null) ? $daten['fehler'] : null,
            'eingereicht' => is_int($daten['eingereicht'] ?? null) ? $daten['eingereicht'] : 0,
            'abgelehnt' => is_int($daten['abgelehnt'] ?? null) ? $daten['abgelehnt'] : 0,
        ];
    }
}
