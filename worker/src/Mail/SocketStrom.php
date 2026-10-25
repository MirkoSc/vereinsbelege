<?php

declare(strict_types=1);

namespace App\Worker\Mail;

use App\Domain\ImapSicherheit;

/**
 * The real connection to the IMAP server (issue #87, E-54): TLS only.
 * `ssl` speaks TLS from the first byte (IMAPS), `starttls` opens plain TCP
 * that the client must upgrade before it sends anything but CAPABILITY
 * and STARTTLS (ImapClient refuses to log in otherwise).
 *
 * The certificate is always verified against the system's CA store and
 * the configured host name; there is no switch to turn that off - a
 * self-signed server certificate needs a CA the container trusts.
 * TLS 1.2 or newer, no compression.
 */
final class SocketStrom implements ImapStrom
{
    /** Longest line we accept outside a literal (a SEARCH answer can be long). */
    private const int MAX_ZEILE = 4 * 1024 * 1024;

    private const int TLS_METHODEN = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    private bool $tls;

    /** @param resource $socket */
    private function __construct(private $socket, bool $tls)
    {
        $this->tls = $tls;
    }

    /**
     * An already connected socket (tests, and oeffnen() itself).
     *
     * @param resource $socket
     */
    public static function ausSocket($socket, bool $tls): self
    {
        return new self($socket, $tls);
    }

    /** @throws ImapFehler */
    public static function oeffnen(string $host, int $port, ImapSicherheit $sicherheit, int $zeitlimit = 60): self
    {
        $kontext = stream_context_create(['ssl' => self::tlsOptionen($host)]);
        $schema = $sicherheit === ImapSicherheit::Ssl ? 'tls' : 'tcp';
        $adresse = sprintf('%s://%s:%d', $schema, str_contains($host, ':') ? '[' . $host . ']' : $host, $port);
        $fehlerNr = 0;
        $fehlerText = '';
        $socket = @stream_socket_client($adresse, $fehlerNr, $fehlerText, $zeitlimit, STREAM_CLIENT_CONNECT, $kontext);
        if ($socket === false) {
            // A failed handshake shows up here as well; tell it apart from
            // an unreachable server by trying the plain connection.
            $roh = $sicherheit === ImapSicherheit::Ssl ? @stream_socket_client(sprintf('tcp://%s:%d', str_contains($host, ':') ? '[' . $host . ']' : $host, $port), $fehlerNr, $fehlerText, $zeitlimit) : false;
            if (is_resource($roh)) {
                fclose($roh);

                throw new ImapFehler('imap_tls', 'TLS-Verbindung zum IMAP-Server fehlgeschlagen.');
            }

            throw new ImapFehler('imap_verbindung', 'IMAP-Server nicht erreichbar.');
        }
        stream_set_timeout($socket, $zeitlimit);

        return new self($socket, $sicherheit === ImapSicherheit::Ssl);
    }

    /**
     * The TLS context: verify peer and name, no self-signed shortcut.
     *
     * @return array<string, mixed>
     */
    public static function tlsOptionen(string $host): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => self::TLS_METHODEN,
        ];
    }

    public function zeile(): string
    {
        $zeile = fgets($this->socket, self::MAX_ZEILE);
        if ($zeile === false || $zeile === '') {
            throw new ImapFehler('imap_verbindung', 'Die Verbindung zum IMAP-Server ist abgebrochen.');
        }
        if (!str_ends_with($zeile, "\n")) {
            throw new ImapFehler(stream_get_meta_data($this->socket)['timed_out'] ? 'imap_verbindung' : 'imap_protokoll', 'Unerwartete Antwort des IMAP-Servers.');
        }

        return $zeile;
    }

    public function lesen(int $bytes): string
    {
        $daten = '';
        while (strlen($daten) < $bytes) {
            $stueck = fread($this->socket, min(65536, $bytes - strlen($daten)));
            if ($stueck === false || $stueck === '') {
                throw new ImapFehler('imap_verbindung', 'Die Verbindung zum IMAP-Server ist abgebrochen.');
            }
            $daten .= $stueck;
        }

        return $daten;
    }

    public function schreiben(string $daten): void
    {
        $rest = $daten;
        while ($rest !== '') {
            $geschrieben = @fwrite($this->socket, $rest);
            if ($geschrieben === false || $geschrieben === 0) {
                throw new ImapFehler('imap_verbindung', 'Die Verbindung zum IMAP-Server ist abgebrochen.');
            }
            $rest = substr($rest, $geschrieben);
        }
    }

    public function tlsStarten(): void
    {
        // Bytes already buffered after the server's STARTTLS answer came in
        // the clear - an attacker on the line could have appended forged
        // answers that would then pass as protected (STARTTLS response
        // injection, CVE-2011-0411 style). Refuse instead of reading on.
        if (stream_get_meta_data($this->socket)['unread_bytes'] > 0) {
            throw new ImapFehler('imap_tls', 'Unerwartete Daten vor dem TLS-Start.');
        }
        $ok = @stream_socket_enable_crypto($this->socket, true, self::TLS_METHODEN);
        if ($ok !== true) {
            throw new ImapFehler('imap_tls', 'TLS-Verbindung zum IMAP-Server fehlgeschlagen.');
        }
        $this->tls = true;
    }

    public function verschluesselt(): bool
    {
        return $this->tls;
    }

    public function schliessen(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
