<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Worker\WorkerKonfiguration;
use App\Worker\Mail\MailZugang;

/**
 * The AI provider profiles with their API keys, as the web host's admin
 * configured them (docs/spec/07-worker.md section 3 "KI-Zugangsdaten"):
 * sealed to W_pub, fetched with the heartbeat, kept in memory only - never
 * written to disk, never logged.
 *
 * A new configuration replaces the old one COMPLETELY: an empty list (the
 * module was switched off, the worker stopped reporting `ai`, the admin
 * removed the profiles) drops every key held before at once (section 5,
 * review of #52). So does an envelope that does not open or has an
 * unknown format - better no AI than a stale key.
 *
 * The same envelope carries the IMAP access of the mail intake since
 * issue #87 (`mail_eingang`, MailZugang); it is replaced and dropped
 * together with the AI keys.
 */
final class KiKonfiguration
{
    private int $version = 0;

    /** @var list<array{KiAnbieter, string}> */
    private array $profile = [];

    private ?MailZugang $mail = null;

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Takes over the heartbeat's `config` (`{version, versiegelt}`).
     *
     * @param array<string, mixed> $config
     */
    public function uebernehmen(array $config, #[\SensitiveParameter] string $schluesselpaar): void
    {
        $version = is_int($config['version'] ?? null) ? $config['version'] : 0;
        $versiegelt = is_string($config['versiegelt'] ?? null) ? base64_decode($config['versiegelt'], true) : false;
        $json = is_string($versiegelt) ? sodium_crypto_box_seal_open($versiegelt, $schluesselpaar) : false;
        $this->verwerfen();
        $this->version = $version;
        if ($json === false) {
            return;
        }
        $daten = json_decode($json, true);
        sodium_memzero($json);
        if (!is_array($daten) || ($daten['format'] ?? null) !== WorkerKonfiguration::FORMAT || !is_array($daten['ki_anbieter'] ?? null)) {
            return;
        }
        $this->mail = is_array($daten['mail_eingang'] ?? null) ? MailZugang::ausKonfiguration($daten['mail_eingang']) : null;
        foreach ($daten['ki_anbieter'] as $p) {
            $profil = is_array($p) ? self::profil($p) : null;
            if ($profil !== null) {
                $this->profile[] = $profil;
            }
        }
    }

    /**
     * Drops every profile and key - and the version, so the next heartbeat
     * reports 0 and the web host sends the current configuration again.
     */
    public function verwerfen(): void
    {
        $this->profile = [];
        $this->mail = null;
        $this->version = 0;
    }

    /** The IMAP access of the mail intake, or null (issue #87). */
    public function mailZugang(): ?MailZugang
    {
        return $this->mail;
    }

    /** @return ?array{KiAnbieter, string} the default profile with its key */
    public function standard(): ?array
    {
        foreach ($this->profile as $eintrag) {
            if ($eintrag[0]->isDefault && $eintrag[0]->nutzbar()) {
                return $eintrag;
            }
        }

        return null;
    }

    /** @return ?array{KiAnbieter, string} the fallback profile with its key, never $ausser */
    public function fallback(int $ausser): ?array
    {
        foreach ($this->profile as $eintrag) {
            if ($eintrag[0]->isFallback && $eintrag[0]->nutzbar() && $eintrag[0]->id !== $ausser) {
                return $eintrag;
            }
        }

        return null;
    }

    public function anzahl(): int
    {
        return count($this->profile);
    }

    /**
     * @param array<mixed> $p
     *
     * @return ?array{KiAnbieter, string}
     */
    private static function profil(array $p): ?array
    {
        $key = is_string($p['api_key'] ?? null) ? $p['api_key'] : '';
        if (!is_int($p['id'] ?? null) || !is_string($p['base_url'] ?? null) || !is_string($p['model'] ?? null) || !is_array($p['faehigkeiten'] ?? null)) {
            return null;
        }
        try {
            $faehigkeiten = KiFaehigkeiten::fromJson(json_encode($p['faehigkeiten'], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }

        return [
            new KiAnbieter(
                $p['id'],
                is_string($p['name'] ?? null) ? $p['name'] : '',
                $p['base_url'],
                $p['model'],
                $faehigkeiten,
                is_int($p['timeout_s'] ?? null) ? max(1, $p['timeout_s']) : 60,
                true,
                ($p['standard'] ?? false) === true,
                $key !== '',
                ($p['fallback'] ?? false) === true,
            ),
            $key,
        ];
    }
}
