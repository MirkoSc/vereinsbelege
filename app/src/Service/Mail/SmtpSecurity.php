<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Transport encryption of an SMTP connection (docs/spec/06-betrieb.md
 * section 3: ports 465/587, TLS).
 */
enum SmtpSecurity: string
{
    /** TLS from the very first byte (ssl://), conventionally port 465. */
    case Implizit = 'implizit';

    /** Plain connect, then STARTTLS, conventionally port 587. */
    case Starttls = 'starttls';

    /** No encryption - local development against a container-internal relay only. */
    case Keine = 'keine';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Implizit => 'Implizit (TLS von Anfang an, Port 465)',
            self::Starttls => 'STARTTLS (Port 587)',
            self::Keine => 'Keine Verschlüsselung',
        };
    }
}
