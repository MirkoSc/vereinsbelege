<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\Mail\OffeneBelegeErinnerung;

/**
 * Reminder mails "N Belege warten auf Prüfung" (docs/spec/06-betrieb.md
 * section 4, issue #249). Runs in `jedesMal`, ahead of the mail queue, so a
 * reminder queued in this call goes out in the same call; when none is due
 * it costs two setting reads. Counts structure columns only, decrypts
 * nothing but the addresses (server key).
 */
final readonly class ErinnerungTask implements CronTask
{
    public function __construct(private OffeneBelegeErinnerung $erinnerung)
    {
    }

    public function name(): string
    {
        return 'erinnerung_offene_belege';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->erinnerung->ausfuehren($now);
    }
}
