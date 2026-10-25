<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * The byte stream under the IMAP client: a TLS socket in operation
 * (SocketStrom), a script in the tests.
 */
interface ImapStrom
{
    /**
     * One line including its CRLF.
     *
     * @throws ImapFehler `imap_verbindung` when the connection broke or timed out
     */
    public function zeile(): string;

    /**
     * Exactly $bytes bytes (an IMAP literal).
     *
     * @throws ImapFehler
     */
    public function lesen(int $bytes): string;

    /** @throws ImapFehler */
    public function schreiben(string $daten): void;

    /**
     * Upgrades the connection to TLS after STARTTLS, verifying the
     * certificate and the host name.
     *
     * @throws ImapFehler `imap_tls`
     */
    public function tlsStarten(): void;

    /** Whether the stream is encrypted right now. */
    public function verschluesselt(): bool;

    public function schliessen(): void;
}
