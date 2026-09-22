<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Fallback transport using PHP's built-in mail() (docs/spec/06-betrieb.md
 * section 3: "Fallback PHP mail() wählbar"). Delegates the actual sending to
 * the host's configured MTA - useful when a hoster only offers a local
 * sendmail-style relay and no real SMTP account.
 */
final class PhpMailTransport implements MailTransport
{
    public function send(MailMessage $mail, MailSettings $settings): void
    {
        $ok = @mail($mail->to, $mail->encodedSubject(), $mail->encodedBody(), $mail->additionalHeaders($settings));
        if (!$ok) {
            throw new MailException('Die PHP-Funktion mail() hat den Versand abgelehnt.');
        }
    }
}
