<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Real SmtpConnection over a PHP stream socket. The protocol handling
 * (multi-line replies, STARTTLS) mirrors the raw-socket SMTP client already
 * proven against the target host in tools/hosting-check.php ("hc_run_smtp"),
 * which the M0 hosting check ran successfully (docs/hosting-befunde.md).
 */
final class StreamSmtpConnection implements SmtpConnection
{
    /**
     * @param resource $socket
     */
    private function __construct(private $socket)
    {
    }

    public static function open(string $transport, string $host, int $port, int $timeoutSeconds): self
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            // Deliberately not $errstr: on some platforms it can echo back
            // the host name, and the message ends up in flashes and logs.
            throw new MailException('SMTP-Verbindung konnte nicht hergestellt werden.');
        }
        stream_set_timeout($socket, $timeoutSeconds);

        return new self($socket);
    }

    public function writeLine(string $line): void
    {
        fwrite($this->socket, $line . "\r\n");
    }

    public function writeRaw(string $data): void
    {
        fwrite($this->socket, $data);
    }

    public function readResponseCode(): int
    {
        $code = 0;
        while (($line = fgets($this->socket, 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            // A hyphen in the 4th column means another line follows
            // ("250-STARTTLS"); anything else ends the reply.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($code === 0) {
            throw new MailException('Die SMTP-Verbindung wurde unerwartet beendet.');
        }

        return $code;
    }

    public function startTls(): bool
    {
        return @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
