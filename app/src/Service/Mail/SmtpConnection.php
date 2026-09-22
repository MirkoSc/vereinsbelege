<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * One live line-based SMTP connection, used by App\Service\Mail\SmtpTransport.
 *
 * Splitting this out of SmtpTransport is the test seam: tests/Service/Mail/
 * SmtpTransportTest.php replaces it with an in-memory fake and never opens a
 * socket, so the suite stays fast and network-free while still exercising
 * the exact command sequence and its error handling.
 */
interface SmtpConnection
{
    /** Writes one line, CRLF appended. */
    public function writeLine(string $line): void;

    /** Writes bytes exactly as given - the DATA payload, which supplies its own CRLF. */
    public function writeRaw(string $data): void;

    /**
     * Reads one full SMTP reply (possibly several `250-` continuation lines)
     * and returns its three-digit code.
     *
     * @throws MailException if the connection ends before a code arrives
     */
    public function readResponseCode(): int;

    /** Layers TLS onto the connection (STARTTLS). */
    public function startTls(): bool;

    public function close(): void;
}
