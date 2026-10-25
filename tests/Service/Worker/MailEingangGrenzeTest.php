<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Repository\RateLimitRepository;
use App\Service\RateLimiter;
use App\Service\Worker\MailEingangGrenze;

/**
 * The web host's own limit on the mail intake (issue #243, N-17): mails
 * and bytes per worker and hour, settings with defaults.
 */
final class MailEingangGrenzeTest extends WorkerTestCase
{
    private function grenze(): MailEingangGrenze
    {
        return new MailEingangGrenze(new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::SUBMISSION_WINDOW_SECONDS), $this->settings);
    }

    public function testDefaultsAndUnusableValues(): void
    {
        self::assertSame(60, $this->grenze()->mailsStunde());
        self::assertSame(500, $this->grenze()->mbStunde());

        foreach (['0', '-3', 'viel', ''] as $wert) {
            $this->settings->set(MailEingangGrenze::SETTING_MAILS_STUNDE, $wert);
            $this->settings->set(MailEingangGrenze::SETTING_MB_STUNDE, $wert);
            self::assertSame(60, $this->grenze()->mailsStunde(), 'no limit switched off by "' . $wert . '"');
            self::assertSame(500, $this->grenze()->mbStunde());
        }
        $this->settings->set(MailEingangGrenze::SETTING_MAILS_STUNDE, '5');
        $this->settings->set(MailEingangGrenze::SETTING_MB_STUNDE, '2');
        self::assertSame(5, $this->grenze()->mailsStunde());
        self::assertSame(2, $this->grenze()->mbStunde());
    }

    public function testMailsAndBytesPerWorkerAndHour(): void
    {
        $this->settings->set(MailEingangGrenze::SETTING_MAILS_STUNDE, '2');
        $this->settings->set(MailEingangGrenze::SETTING_MB_STUNDE, '1');
        $grenze = $this->grenze();
        $jetzt = new \DateTimeImmutable('2026-10-25 10:15:00');

        $grenze->mailZaehlen(1, $jetzt);
        self::assertTrue($grenze->mailErlaubt(1, $jetzt));
        $grenze->mailZaehlen(1, $jetzt);
        self::assertFalse($grenze->mailErlaubt(1, $jetzt), 'two per hour');
        self::assertTrue($grenze->mailErlaubt(2, $jetzt), 'per worker');

        self::assertTrue($grenze->bytesErlaubt(1, 1024 * 1024, $jetzt), 'exactly one MiB fits');
        $grenze->bytesZaehlen(1, 700 * 1024, $jetzt);
        self::assertTrue($grenze->bytesErlaubt(1, 324 * 1024, $jetzt));
        self::assertFalse($grenze->bytesErlaubt(1, 324 * 1024 + 1, $jetzt), 'counted in whole KiB, rounded up');
        $grenze->bytesZaehlen(1, 1, $jetzt);
        self::assertFalse($grenze->bytesErlaubt(1, 324 * 1024, $jetzt));
        self::assertTrue($grenze->bytesErlaubt(2, 1024 * 1024, $jetzt));

        $spaeter = $jetzt->modify('+1 hour');
        self::assertTrue($grenze->mailErlaubt(1, $spaeter), 'the next hour starts from zero');
        self::assertTrue($grenze->bytesErlaubt(1, 1024 * 1024, $spaeter));
    }
}
