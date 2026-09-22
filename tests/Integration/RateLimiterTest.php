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

    private static function zeit(string $uhrzeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-01 ' . $uhrzeit);
    }
}
