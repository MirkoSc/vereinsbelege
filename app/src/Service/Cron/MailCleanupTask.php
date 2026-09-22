<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Repository\MailQueueRepository;

/**
 * Removes sent mails after a week, mirroring JobCleanupTask. `fehler` rows
 * stay - the admin queue view (issue #14) is where a human finds out mail is
 * not going out.
 */
final readonly class MailCleanupTask implements CronTask
{
    public const int KEEP_DAYS = 7;

    public function __construct(private MailQueueRepository $queue)
    {
    }

    public function name(): string
    {
        return 'mails_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->queue->deleteSentBefore($now->modify(sprintf('-%d days', self::KEEP_DAYS)));
    }
}
