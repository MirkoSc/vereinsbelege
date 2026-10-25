<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\RateLimitRepository;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;

/**
 * The brute force counter of docs/spec/01-sicherheit.md section 3, taken
 * over from MirkoSc/vereinskalender and generalised to a namespaced key.
 *
 * Every test pins its own "now": a fixed window is exactly the kind of thing
 * that passes at 10:05 and fails at 10:59:59 if the clock is left to itself.
 */
final class RateLimiterTest extends DatabaseTestCase
{
    private RateLimiter $limits;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->limits = new RateLimiter(new RateLimitRepository($this->pdo()), windowSeconds: 900);
    }

    public function testNothingIsBlockedToBeginWith(): void
    {
        self::assertFalse($this->limits->isBlocked('login.ip', '198.51.100.7', 3, self::zeit('10:00:00')));
    }

    public function testTheDoorClosesWhenTheLimitIsReached(): void
    {
        $jetzt = self::zeit('10:00:00');

        $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        self::assertFalse($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));

        $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        self::assertTrue($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));
    }

    /** Asking must not cost an attempt, or the check would lock people out. */
    public function testCheckingDoesNotCount(): void
    {
        $jetzt = self::zeit('10:00:00');
        $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);

        for ($i = 0; $i < 10; $i++) {
            $this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt);
        }

        self::assertFalse($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));
    }

    public function testTheNextWindowStartsFromZero(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 3; $i++) {
            $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        }
        self::assertTrue($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));

        self::assertFalse(
            $this->limits->isBlocked('login.ip', '198.51.100.7', 3, self::zeit('10:15:00')),
            'Nach dem Fenster ist der Zähler wieder frei.',
        );
    }

    /** One row per key, no matter how many attempts land in the window. */
    public function testTheTableKeepsOneRowPerKey(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 5; $i++) {
            $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        }
        $this->limits->registerFailure('login.ip', '198.51.100.7', self::zeit('10:15:00'));

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    public function testASuccessClearsTheCounter(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 3; $i++) {
            $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        }

        $this->limits->reset('login.ip', '198.51.100.7');

        self::assertFalse($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    /**
     * The namespace is what allows one table to carry the IP counter and the
     * account counter at once (docs/spec/01-sicherheit.md section 3: per IP
     * *and* per account).
     */
    public function testPurposesDoNotShareACounter(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 3; $i++) {
            $this->limits->registerFailure('login.ip', 'gleich', $jetzt);
        }

        self::assertTrue($this->limits->isBlocked('login.ip', 'gleich', 3, $jetzt));
        self::assertFalse($this->limits->isBlocked('login.account', 'gleich', 3, $jetzt));
    }

    public function testDifferentValuesDoNotShareACounter(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 3; $i++) {
            $this->limits->registerFailure('login.ip', '198.51.100.7', $jetzt);
        }

        self::assertFalse($this->limits->isBlocked('login.ip', '198.51.100.8', 3, $jetzt));
    }

    /** The value itself never reaches the database (section 5). */
    public function testTheKeyIsStoredAsADigestOnly(): void
    {
        $this->limits->registerFailure('login.ip', '198.51.100.7', self::zeit('10:00:00'));

        $stmt = $this->pdo()->query('SELECT key_hash FROM rate_limit');
        $gespeichert = (string) $stmt->fetchColumn();

        self::assertSame(32, strlen($gespeichert), 'SHA-256, roh.');
        self::assertStringNotContainsString('198.51.100.7', $gespeichert);
    }

    /**
     * A request without an address (command line, a host that hides it) must
     * not end up sharing one counter with everybody else.
     */
    public function testAnEmptyValueIsNeverBlocked(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 10; $i++) {
            $this->limits->registerFailure('login.ip', '', $jetzt);
        }

        self::assertFalse($this->limits->isBlocked('login.ip', '', 3, $jetzt));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    public function testCleanupRemovesRowsThatCanNoLongerBlockAnybody(): void
    {
        $this->limits->registerFailure('login.ip', 'alt', self::zeit('10:00:00'));
        $this->limits->registerFailure('login.ip', 'neu', self::zeit('11:00:00'));

        $entfernt = $this->limits->cleanup(self::zeit('11:00:00'));

        self::assertSame(1, $entfernt);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    /**
     * Security review N-09 (issue #246): versuch() counts as it checks, so
     * the attempts let through never exceed the limit - however many
     * requests ask before the first of them has failed.
     */
    public function testVersuchLetsExactlyTheLimitThrough(): void
    {
        $jetzt = self::zeit('10:00:00');

        $zugelassen = 0;
        for ($i = 0; $i < 10; $i++) {
            $zugelassen += $this->limits->versuch('login.ip', '198.51.100.7', 3, $jetzt) ? 1 : 0;
        }

        self::assertSame(3, $zugelassen);
        self::assertTrue($this->limits->isBlocked('login.ip', '198.51.100.7', 3, $jetzt));
    }

    /**
     * The interleaving of parallel requests on separate connections: all of
     * them ask before any of them knows whether its guess was right. With
     * "isBlocked(), check, registerFailure()" every one of them got
     * through; with versuch() exactly the limit does.
     */
    public function testParallelConnectionsDoNotOvershootTheLimit(): void
    {
        $jetzt = self::zeit('10:00:00');
        $limiter = [$this->limits];
        for ($i = 0; $i < 4; $i++) {
            $limiter[] = new RateLimiter(new RateLimitRepository($this->zweiteVerbindung()), windowSeconds: 900);
        }

        $alt = 0;
        foreach ($limiter as $l) {
            $alt += $l->isBlocked('mfa.account', '7', 3, $jetzt) ? 0 : 1;
        }
        self::assertSame(5, $alt, 'the read-only check alone lets every parallel request through');

        $zugelassen = 0;
        foreach ($limiter as $l) {
            $zugelassen += $l->versuch('mfa.account', '7', 3, $jetzt) ? 1 : 0;
        }
        self::assertSame(3, $zugelassen);
    }

    public function testVersuchStartsANewWindowFromOne(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:00:00'));
        }

        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:00')));
        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:01')));
        self::assertTrue($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:02')));
        self::assertFalse($this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:03')));
    }

    /** A request with a slightly older clock does not roll a newer window back. */
    public function testAnOlderClockDoesNotResetANewerWindow(): void
    {
        $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:00'));
        $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:14:59'));
        $this->limits->versuch('login.ip', '198.51.100.7', 3, self::zeit('10:15:01'));

        self::assertTrue($this->limits->isBlocked('login.ip', '198.51.100.7', 3, self::zeit('10:15:02')));
    }

    /** A success hands its count back - only failures stay counted. */
    public function testErstattenTakesTheAttemptBack(): void
    {
        $jetzt = self::zeit('10:00:00');
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->limits->versuch('worker.pair', '198.51.100.7', 3, $jetzt));
            $this->limits->erstatten('worker.pair', '198.51.100.7', $jetzt);
        }

        self::assertFalse($this->limits->isBlocked('worker.pair', '198.51.100.7', 3, $jetzt));
        // Never below zero, and not into a window that has moved on.
        $this->limits->erstatten('worker.pair', '198.51.100.7', $jetzt);
        $this->limits->erstatten('worker.pair', '198.51.100.7', self::zeit('10:15:00'));
        self::assertSame(0, (int) $this->pdo()->query('SELECT `count` FROM rate_limit')->fetchColumn());
    }

    public function testVersuchWithAnEmptyValueNeitherCountsNorBlocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->limits->versuch('login.ip', '', 1, self::zeit('10:00:00')));
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn());
    }

    private function zweiteVerbindung(): \PDO
    {
        $db = self::configData()['db'];
        assert(is_array($db));

        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            (string) $db['user'],
            (string) $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        );
    }

    private static function zeit(string $uhrzeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-01 ' . $uhrzeit);
    }
}
