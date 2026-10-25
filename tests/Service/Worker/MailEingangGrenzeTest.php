<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Repository\RateLimitRepository;
use App\Service\RateLimiter;
use App\Service\Worker\MailEingangGrenze;

/**
 * The web host's own limit on the mail intake (issue #243, N-17): mails
 * and bytes per worker and hour, settings with defaults, reserved
 * atomically (RateLimiter::reserve()).
 */
final class MailEingangGrenzeTest extends WorkerTestCase
{
    private function limiter(): RateLimiter
    {
        return new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::SUBMISSION_WINDOW_SECONDS);
    }

    private function grenze(): MailEingangGrenze
    {
        return new MailEingangGrenze($this->limiter(), $this->settings);
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

        self::assertTrue($grenze->mailReservieren(1, $jetzt));
        self::assertTrue($grenze->mailErlaubt(1, $jetzt));
        self::assertTrue($grenze->mailReservieren(1, $jetzt));
        self::assertFalse($grenze->mailErlaubt(1, $jetzt), 'two per hour');
        self::assertFalse($grenze->mailReservieren(1, $jetzt));
        $grenze->mailFreigeben(1, $jetzt);
        self::assertTrue($grenze->mailReservieren(1, $jetzt), 'a mail that did not become a receipt gives its place back');
        self::assertTrue($grenze->mailReservieren(2, $jetzt), 'per worker');

        self::assertTrue($grenze->bytesReservieren(1, 700 * 1024, $jetzt));
        self::assertFalse($grenze->bytesReservieren(1, 324 * 1024 + 1, $jetzt), 'counted in whole KiB, rounded up');
        self::assertTrue($grenze->bytesReservieren(1, 324 * 1024, $jetzt), 'exactly one MiB in all');
        self::assertFalse($grenze->bytesReservieren(1, 1, $jetzt));
        self::assertTrue($grenze->bytesReservieren(2, 1024 * 1024, $jetzt));
        self::assertFalse($grenze->passtNie(1024 * 1024));
        self::assertTrue($grenze->passtNie(1024 * 1024 + 1));

        $spaeter = $jetzt->modify('+1 hour');
        self::assertTrue($grenze->mailErlaubt(1, $spaeter), 'the next hour starts from zero');
        self::assertTrue($grenze->bytesReservieren(1, 1024 * 1024, $spaeter));
    }

    public function testAReservationOpensANewWindowAndNeverGoesBelowZero(): void
    {
        $limiter = $this->limiter();
        $jetzt = new \DateTimeImmutable('2026-10-25 10:15:00');
        self::assertTrue($limiter->reserve('x', 'w', 9, 10, $jetzt));
        self::assertFalse($limiter->reserve('x', 'w', 2, 10, $jetzt));
        self::assertFalse($limiter->reserve('x', 'w', 11, 10, $jetzt->modify('+1 hour')), 'more than the limit never fits');
        self::assertTrue($limiter->reserve('x', 'w', 10, 10, $jetzt->modify('+1 hour')), 'an old window is replaced, not added to');
        $limiter->release('x', 'w', 50, $jetzt->modify('+1 hour'));
        self::assertSame(0, (int) $this->pdo()->query('SELECT `count` FROM rate_limit')->fetchColumn());
    }

    /**
     * Parallel requests cannot pass the limit together: several processes
     * reserve on their own connections at the same moment - granted is
     * exactly what fits, the counter equals it.
     */
    public function testParallelReservationsNeverExceedTheLimit(): void
    {
        // The dev container disables proc_open (like the host); CI runs this.
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available (disable_functions).');
        }
        $daten = self::configData()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $daten['host'], $daten['port'], $daten['name']);
        $start = (string) time();
        $prozesse = [];
        for ($i = 0; $i < 6; ++$i) {
            $prozess = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/ratelimit/reservieren.php', $dsn, $daten['user'], $daten['password'], '40', '3', '200', $start],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $rohre,
            );
            self::assertIsResource($prozess);
            $prozesse[] = [$prozess, $rohre];
        }
        $gewaehrt = 0;
        foreach ($prozesse as [$prozess, $rohre]) {
            $ausgabe = stream_get_contents($rohre[1]);
            $fehler = stream_get_contents($rohre[2]);
            fclose($rohre[1]);
            fclose($rohre[2]);
            self::assertSame(0, proc_close($prozess), (string) $fehler);
            $gewaehrt += (int) $ausgabe;
        }

        // 240 attempts of 3 against 200: 66 fit (198).
        self::assertSame(66, $gewaehrt);
        self::assertSame(198, (int) $this->pdo()->query('SELECT `count` FROM rate_limit')->fetchColumn());
    }
}
