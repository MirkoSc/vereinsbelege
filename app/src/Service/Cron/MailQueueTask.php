<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\Mail\Mailer;

/**
 * Sends everything due in the mail queue (docs/spec/06-betrieb.md section 3,
 * issue #14). Runs in `jedesMal`, not `aufraeumen`: a security mail's retry
 * must not wait for the next housekeeping window.
 */
final readonly class MailQueueTask implements CronTask
{
    /** How many mails one cron call sends at most - a call must stay short (CLAUDE.md section 1). */
    private const int BATCH = 10;

    public function __construct(private Mailer $mailer)
    {
    }

    public function name(): string
    {
        return 'mail_versenden';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->mailer->sendeFaellige($now, self::BATCH);
    }
}
