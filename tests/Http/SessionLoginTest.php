<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Session;
use App\Service\Account\SessionTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * Login state and the two timeouts of docs/spec/01-sicherheit.md section 2
 * (30 minutes idle, 12 hours absolute).
 *
 * $_SESSION is set directly, like tests/Http/SessionFlashTest.php does -
 * login() itself regenerates a session id and is exercised through the
 * flow test instead.
 */
final class SessionLoginTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->session = new Session();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAFreshSessionHasNoUser(): void
    {
        self::assertNull($this->session->userId());
    }

    public function testLoginRecordsTheUserAndBothTimestamps(): void
    {
        $jetzt = new \DateTimeImmutable('2026-03-01 10:00:00');

        $this->session->login(42, $jetzt);

        self::assertSame(42, $this->session->userId());
        self::assertSame($jetzt->getTimestamp(), $_SESSION['login_at']);
        self::assertSame($jetzt->getTimestamp(), $_SESSION['last_seen_at']);
    }

    /**
     * The CSRF token of the anonymous login form must not stay valid for the
     * session that form created (session fixation, section 3).
     */
    public function testLoginDropsTheTokenOfTheAnonymousForm(): void
    {
        $vorher = $this->session->csrfToken();

        $this->session->login(42);

        self::assertNotSame($vorher, $this->session->csrfToken());
    }

    public function testAnIdleSessionExpires(): void
    {
        $this->session->login(42, new \DateTimeImmutable('2026-03-01 10:00:00'));

        self::assertFalse($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
            new \DateTimeImmutable('2026-03-01 10:29:00'),
        ));
        self::assertTrue($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
            new \DateTimeImmutable('2026-03-01 10:30:00'),
        ));
    }

    /** Working all day does not make the session immortal. */
    public function testABusySessionStillExpiresAfterTheAbsoluteLimit(): void
    {
        $this->session->login(42, new \DateTimeImmutable('2026-03-01 08:00:00'));
        $this->session->touch(new \DateTimeImmutable('2026-03-01 19:59:00'));

        self::assertFalse($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
            new \DateTimeImmutable('2026-03-01 19:59:30'),
        ));
        self::assertTrue($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
            new \DateTimeImmutable('2026-03-01 20:00:00'),
        ));
    }

    public function testTouchPushesTheIdleTimeoutOut(): void
    {
        $this->session->login(42, new \DateTimeImmutable('2026-03-01 10:00:00'));
        $this->session->touch(new \DateTimeImmutable('2026-03-01 10:25:00'));

        self::assertFalse($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
            new \DateTimeImmutable('2026-03-01 10:50:00'),
        ));
    }

    /**
     * A session left over from an older release carries no timestamps. It
     * cannot be proven fresh, so it counts as expired - one login is the
     * whole cost of being wrong here.
     */
    public function testASessionWithoutTimestampsCountsAsExpired(): void
    {
        $_SESSION['user_id'] = 42;

        self::assertTrue($this->session->isExpired(
            SessionTimeouts::IDLE_DEFAULT,
            SessionTimeouts::ABSOLUTE_DEFAULT,
        ));
    }

    /** A user id that is not an id is not a login. */
    public function testAGarbledUserIdIsNoLogin(): void
    {
        $_SESSION['user_id'] = 'sieben';
        self::assertNull($this->session->userId());

        $_SESSION['user_id'] = 0;
        self::assertNull($this->session->userId());
    }
}
