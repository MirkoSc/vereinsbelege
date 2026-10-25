<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\ImapSicherheit;

/**
 * The mail intake's settings (issue #87, E-54, docs/spec/07-worker.md
 * section 9): which IMAP mailbox the worker reads, how it treats what it
 * handled, which senders it accepts and how often it looks.
 *
 * The password and the sender list are secrets/personal data: stored only
 * under the server key (MailEingangSpeicher), never shown again, and
 * handed to the worker only sealed to W_pub (WorkerKonfiguration).
 */
final readonly class MailEingangEinstellungen
{
    public const int ABRUF_MINUTEN_STANDARD = 5;

    public const int ABRUF_MINUTEN_MIN = 1;

    public const int ABRUF_MINUTEN_MAX = 60;

    public const string ORDNER_STANDARD = 'INBOX';

    private const int ORDNER_MAX = 200;

    private const int BENUTZER_MAX = 254;

    private const int PASSWORT_MAX = 1024;

    public function __construct(
        public bool $aktiv = false,
        public string $host = '',
        public int $port = 993,
        public ImapSicherheit $sicherheit = ImapSicherheit::Ssl,
        public string $benutzer = '',
        #[\SensitiveParameter] public string $passwort = '',
        public string $ordner = self::ORDNER_STANDARD,
        public string $zielOrdner = '',
        public MailAbsenderListe $absender = new MailAbsenderListe(),
        public int $abrufMinuten = self::ABRUF_MINUTEN_STANDARD,
        // Day the intake was switched on (Y-m-d): older mail in the folder
        // is never handed in - pointing it at a mailbox with years of mail
        // must not flood the inbox.
        public ?string $aktivSeit = null,
    ) {
    }

    /** Everything the worker needs to log in is there. */
    public function vollstaendig(): bool
    {
        return $this->host !== '' && $this->benutzer !== '' && $this->passwort !== '';
    }

    /**
     * Checks the admin form. The password field is never pre-filled: empty
     * keeps the stored one ($bisher).
     *
     * @param array<string, mixed> $post
     *
     * @return array{0: ?self, 1: array<string, string>} the settings, or
     *         null and German messages per field
     */
    public static function ausFormular(array $post, self $bisher, ?\DateTimeImmutable $heute = null): array
    {
        $fehler = [];
        $text = static fn(string $feld): string => is_string($post[$feld] ?? null) ? trim($post[$feld]) : '';

        $aktiv = ($post['aktiv'] ?? '') === '1';
        $host = strtolower($text('host'));
        if ($host !== '' && !self::hostGueltig($host)) {
            $fehler['host'] = 'Bitte nur den Servernamen angeben, z. B. imap.example.org.';
        }
        $sicherheit = ImapSicherheit::tryFrom($text('sicherheit'));
        if ($sicherheit === null) {
            $fehler['sicherheit'] = 'Bitte eine Verschlüsselung wählen.';
        }
        $portText = $text('port');
        $port = $portText === '' ? ($sicherheit ?? ImapSicherheit::Ssl)->standardPort() : filter_var($portText, FILTER_VALIDATE_INT);
        // The other encryption's usual port left in the field (it is
        // pre-filled): that combination never works, take the right one.
        if ($sicherheit !== null && is_int($port)) {
            foreach (ImapSicherheit::cases() as $andere) {
                if ($andere !== $sicherheit && $port === $andere->standardPort()) {
                    $port = $sicherheit->standardPort();
                }
            }
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            $fehler['port'] = 'Der Port muss zwischen 1 und 65535 liegen.';
        }
        $benutzer = $text('benutzer');
        if (mb_strlen($benutzer) > self::BENUTZER_MAX || preg_match('/[\x00-\x1F\x7F]/', $benutzer) === 1) {
            $fehler['benutzer'] = 'Der Benutzername ist ungültig.';
        }
        // Not trimmed: a password may start or end with a space.
        $neuesPasswort = is_string($post['passwort'] ?? null) ? $post['passwort'] : '';
        if (strlen($neuesPasswort) > self::PASSWORT_MAX || preg_match('/[\x00\r\n]/', $neuesPasswort) === 1) {
            $fehler['passwort'] = 'Das Passwort ist ungültig.';
        }
        $ordner = $text('ordner') === '' ? self::ORDNER_STANDARD : $text('ordner');
        if (!self::ordnerGueltig($ordner)) {
            $fehler['ordner'] = 'Der Ordnername ist ungültig.';
        }
        $zielOrdner = $text('ziel_ordner');
        if ($zielOrdner !== '' && !self::ordnerGueltig($zielOrdner)) {
            $fehler['ziel_ordner'] = 'Der Ordnername ist ungültig.';
        } elseif ($zielOrdner !== '' && strcasecmp($zielOrdner, $ordner) === 0) {
            $fehler['ziel_ordner'] = 'Der Zielordner muss ein anderer als der abgerufene Ordner sein.';
        }
        [$absender, $ungueltig] = MailAbsenderListe::lesen(is_string($post['absender'] ?? null) ? $post['absender'] : '');
        if ($absender === null) {
            $fehler['absender'] = $ungueltig === []
                ? sprintf('Höchstens %d Einträge.', MailAbsenderListe::MAX_EINTRAEGE)
                : 'Keine gültige Adresse oder @Domain: ' . implode(', ', array_slice($ungueltig, 0, 3));
        }
        $minuten = filter_var($text('abruf_minuten') === '' ? (string) self::ABRUF_MINUTEN_STANDARD : $text('abruf_minuten'), FILTER_VALIDATE_INT);
        if (!is_int($minuten) || $minuten < self::ABRUF_MINUTEN_MIN || $minuten > self::ABRUF_MINUTEN_MAX) {
            $fehler['abruf_minuten'] = sprintf('Das Intervall muss zwischen %d und %d Minuten liegen.', self::ABRUF_MINUTEN_MIN, self::ABRUF_MINUTEN_MAX);
        }

        $passwort = $neuesPasswort === '' ? $bisher->passwort : $neuesPasswort;
        if ($aktiv && $fehler === [] && ($host === '' || $benutzer === '' || $passwort === '')) {
            $fehler['aktiv'] = 'Zum Einschalten werden Server, Benutzer und Passwort gebraucht.';
        }
        if ($fehler !== []) {
            return [null, $fehler];
        }
        \assert($sicherheit !== null && is_int($port) && is_int($minuten) && $absender !== null);

        // Switching on (again) starts a new period; staying on keeps it.
        $seit = $aktiv
            ? ($bisher->aktiv && $bisher->aktivSeit !== null ? $bisher->aktivSeit : ($heute ?? new \DateTimeImmutable())->format('Y-m-d'))
            : null;

        return [new self($aktiv, $host, $port, $sicherheit, $benutzer, $passwort, $ordner, $zielOrdner, $absender, $minuten, $seit), []];
    }

    /**
     * What the worker gets in its sealed configuration (`mail_eingang`).
     * The limits are those of the public submission (01 section 5), so a
     * mail can never bring in more than the form.
     *
     * @return array<string, mixed>
     */
    public function fuerWorker(int $maxSeiten, int $maxDateiBytes, int $maxEinreichungBytes): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'sicherheit' => $this->sicherheit->value,
            'benutzer' => $this->benutzer,
            'passwort' => $this->passwort,
            'ordner' => $this->ordner,
            'ziel_ordner' => $this->zielOrdner,
            'absender' => $this->absender->eintraege,
            'abruf_minuten' => $this->abrufMinuten,
            'seit' => $this->aktivSeit,
            'max_seiten' => $maxSeiten,
            'max_datei_bytes' => $maxDateiBytes,
            'max_einreichung_bytes' => $maxEinreichungBytes,
        ];
    }

    /** A day as the intake stores it (Y-m-d), or null. */
    public static function tagOderNull(mixed $wert): ?string
    {
        return is_string($wert) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $wert) !== false
            ? $wert
            : null;
    }

    public static function hostGueltig(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($host) <= 253
            && preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;
    }

    /** Printable, no control characters, no IMAP wildcards. */
    public static function ordnerGueltig(string $ordner): bool
    {
        return $ordner !== ''
            && mb_strlen($ordner) <= self::ORDNER_MAX
            && mb_check_encoding($ordner, 'UTF-8')
            && preg_match('/[\x00-\x1F\x7F*%]/', $ordner) !== 1;
    }
}
