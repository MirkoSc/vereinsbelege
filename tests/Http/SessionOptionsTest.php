<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Session;
use PHPUnit\Framework\TestCase;

/**
 * Security review (issue #78): the session options App\Http\Session passes
 * to session_start() instead of trusting the host's php.ini. A real session
 * needs headers no test process has, so the options are checked as data and
 * against what PHP itself accepts.
 */
final class SessionOptionsTest extends TestCase
{
    public function testUnknownSessionIdsAreNotAdopted(): void
    {
        self::assertSame('1', Session::OPTIONEN['use_strict_mode'] ?? null);
    }

    /**
     * Every page with a session shows decrypted data; "nocache" makes PHP
     * send Cache-Control: no-store with each of them.
     */
    public function testSessionPagesAreNotCached(): void
    {
        self::assertSame('nocache', Session::OPTIONEN['cache_limiter'] ?? null);
    }

    /**
     * Each key must be a real session.* directive - a typo would be a
     * silently ignored option - and none may be one PHP 8.4+ deprecates.
     */
    public function testEveryOptionIsAValidSessionDirective(): void
    {
        $bekannt = ini_get_all('session', false);

        foreach (array_keys(Session::OPTIONEN) as $name) {
            self::assertArrayHasKey('session.' . $name, $bekannt, $name);
            self::assertNotContains($name, ['use_only_cookies', 'use_trans_sid', 'sid_length', 'sid_bits_per_character', 'referer_check', 'trans_sid_tags', 'trans_sid_hosts'], $name);
        }
    }

    public function testStartPassesTheOptions(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Http/Session.php');

        self::assertStringContainsString('session_start(self::OPTIONEN)', $quelle);
        preg_match_all('/^\s*session_start\(([^)]*)\);/m', $quelle, $aufrufe);
        self::assertSame(['self::OPTIONEN'], $aufrufe[1], 'every session_start() call goes through the options');
    }
}
