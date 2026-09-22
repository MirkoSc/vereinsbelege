<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Outcome of one send attempt (App\Service\Mail\Mailer). `fehlermeldung` is
 * only ever a MailException message (safe by construction, see that class)
 * or another throwable's class name - never enough to identify a recipient,
 * so it can be shown in an admin flash message.
 */
final readonly class MailAttemptResult
{
    public function __construct(
        public bool $erfolg,
        public ?string $fehlermeldung = null,
    ) {
    }
}
