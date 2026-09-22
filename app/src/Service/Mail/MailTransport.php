<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Sends one mail. Two implementations: App\Service\Mail\SmtpTransport (the
 * default) and App\Service\Mail\PhpMailTransport (the fallback docs/spec/
 * 06-betrieb.md section 3 calls "wählbar").
 */
interface MailTransport
{
    /**
     * @throws MailException on any failure - the message never contains a
     *         credential, a recipient address or a subject, so it can be
     *         shown to an admin and written to the queue (CLAUDE.md section 4)
     */
    public function send(MailMessage $mail, MailSettings $settings): void;
}
