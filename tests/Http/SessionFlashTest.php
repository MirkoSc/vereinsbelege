<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Session;
use App\View\FlashArt;
use PHPUnit\Framework\TestCase;

/**
 * Flash messages survive a redirect in the session store. The wrapper is
 * tested against $_SESSION directly - starting a real session would need
 * headers no test process has.
 */
final class SessionFlashTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAMessageSurvivesExactlyOneRequest(): void
    {
        $session = new Session();
        $session->flash('Gespeichert.');

        $flash = $session->pullFlash();
        self::assertNotNull($flash);
        self::assertSame('Gespeichert.', $flash->text);
        self::assertSame(FlashArt::Ok, $flash->art);

        self::assertNull($session->pullFlash(), 'a flash is shown once, not on every following page');
    }

    public function testTheSeverityIsKept(): void
    {
        $session = new Session();
        $session->flash('Ging schief.', FlashArt::Fehler);

        self::assertSame(FlashArt::Fehler, $session->pullFlash()?->art);
    }

    public function testNoFlashMeansNull(): void
    {
        self::assertNull((new Session())->pullFlash());
    }

    /**
     * A release switch does not end the running sessions: the store can
     * still hold the plain string an older release wrote. The first page
     * after an update must not be a type error.
     */
    public function testAMessageWrittenByAnOlderReleaseStillRenders(): void
    {
        $_SESSION['flash'] = 'Von der Vorversion geschrieben.';

        $flash = (new Session())->pullFlash();

        self::assertSame('Von der Vorversion geschrieben.', $flash?->text);
        self::assertSame(FlashArt::Ok, $flash?->art);
    }

    /**
     * Same direction, after a rollback: a severity this release does not
     * know falls back instead of throwing.
     */
    public function testAnUnknownSeverityFallsBack(): void
    {
        $_SESSION['flash'] = ['text' => 'Hallo', 'art' => 'katastrophe'];

        self::assertSame(FlashArt::Ok, (new Session())->pullFlash()?->art);
    }

    public function testGarbageInTheStoreIsIgnored(): void
    {
        $_SESSION['flash'] = ['kein_text' => true];

        self::assertNull((new Session())->pullFlash());
    }
}
