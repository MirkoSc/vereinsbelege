<?php

declare(strict_types=1);

namespace App\Worker\Mail;

use App\Domain\ImapSicherheit;
use App\Service\Worker\MailAbsenderListe;
use App\Service\Worker\MailEingangEinstellungen;

/**
 * The mail intake's access as the web host sealed it to W_pub (`mail_eingang`
 * of the worker configuration, issue #87, E-54). Kept in memory only.
 */
final readonly class MailZugang
{
    private function __construct(
        public string $host,
        public int $port,
        public ImapSicherheit $sicherheit,
        public string $benutzer,
        #[\SensitiveParameter] public string $passwort,
        public string $ordner,
        public string $zielOrdner,
        public MailAbsenderListe $absender,
        public int $abrufMinuten,
        public int $maxSeiten,
        public int $maxDateiBytes,
        public int $maxEinreichungBytes,
        /** Only mail that arrived on or after this day (Y-m-d), null = all. */
        public ?string $seit = null,
    ) {
    }

    /**
     * @param array<mixed> $daten
     */
    public static function ausKonfiguration(array $daten): ?self
    {
        $text = static fn(string $feld): string => is_string($daten[$feld] ?? null) ? $daten[$feld] : '';
        $zahl = static fn(string $feld): int => is_int($daten[$feld] ?? null) ? $daten[$feld] : 0;
        $sicherheit = ImapSicherheit::tryFrom($text('sicherheit'));
        $host = strtolower($text('host'));
        if ($sicherheit === null || !MailEingangEinstellungen::hostGueltig($host) || $text('benutzer') === '' || $text('passwort') === ''
            || $zahl('port') < 1 || $zahl('port') > 65535 || !MailEingangEinstellungen::ordnerGueltig($text('ordner'))
            || ($text('ziel_ordner') !== '' && !MailEingangEinstellungen::ordnerGueltig($text('ziel_ordner')))
            || $zahl('max_seiten') < 1 || $zahl('max_datei_bytes') < 1 || $zahl('max_einreichung_bytes') < 1) {
            return null;
        }

        return new self(
            host: $host,
            port: $zahl('port'),
            sicherheit: $sicherheit,
            benutzer: $text('benutzer'),
            passwort: $text('passwort'),
            ordner: $text('ordner'),
            zielOrdner: $text('ziel_ordner'),
            absender: MailAbsenderListe::ausListe(is_array($daten['absender'] ?? null) ? $daten['absender'] : []),
            abrufMinuten: max(MailEingangEinstellungen::ABRUF_MINUTEN_MIN, min(MailEingangEinstellungen::ABRUF_MINUTEN_MAX, $zahl('abruf_minuten'))),
            maxSeiten: $zahl('max_seiten'),
            maxDateiBytes: $zahl('max_datei_bytes'),
            maxEinreichungBytes: $zahl('max_einreichung_bytes'),
            seit: MailEingangEinstellungen::tagOderNull($daten['seit'] ?? null),
        );
    }

    /**
     * Mails larger than this are not fetched: the attachments may bring
     * at most max_einreichung_bytes, and base64 makes them a third larger;
     * 1 MiB for text and headers.
     */
    public function maxMailBytes(): int
    {
        return intdiv($this->maxEinreichungBytes * 4, 3) + 1024 * 1024;
    }

    /** @return array<string, mixed> without the password - for nothing but tests */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'ordner' => $this->ordner];
    }
}
