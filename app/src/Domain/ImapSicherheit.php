<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How the worker's IMAP connection of the mail intake is encrypted
 * (issue #87, E-54, docs/spec/07-worker.md section 9). There is no
 * plaintext option: either TLS from the first byte (IMAPS, usually port
 * 993) or STARTTLS, which the worker insists on before it logs in.
 */
enum ImapSicherheit: string
{
    case Ssl = 'ssl';
    case Starttls = 'starttls';

    public function label(): string
    {
        return match ($this) {
            self::Ssl => 'SSL/TLS (IMAPS, meist Port 993)',
            self::Starttls => 'STARTTLS (meist Port 143)',
        };
    }

    public function standardPort(): int
    {
        return match ($this) {
            self::Ssl => 993,
            self::Starttls => 143,
        };
    }
}
