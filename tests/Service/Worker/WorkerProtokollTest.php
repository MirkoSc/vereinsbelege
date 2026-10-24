<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\WorkerProtokoll;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract (docs/spec/07-worker.md section 4, issue #51/M7b-1):
 * pinned with a fixed vector, so the worker client (issue #54) and the web
 * host cannot drift apart unnoticed.
 */
final class WorkerProtokollTest extends TestCase
{
    public function testTheCanonicalStringCoversMethodPathTimeNonceAndBodyHash(): void
    {
        self::assertSame(
            'POST|/api/worker/heartbeat|1700000000|abcdefghijklmnop|' . hash('sha256', '{"a":1}'),
            WorkerProtokoll::kanonisch('post', '/api/worker/heartbeat', 1700000000, 'abcdefghijklmnop', '{"a":1}'),
        );
    }

    public function testTheSignatureIsHmacSha256OverTheCanonicalString(): void
    {
        $schluessel = str_repeat("\x01", 32);
        $erwartet = hash_hmac('sha256', 'POST|/api/worker/jobs/claim|1700000000|abcdefghijklmnop|' . hash('sha256', ''), $schluessel);

        self::assertSame($erwartet, WorkerProtokoll::signatur($schluessel, 'POST', '/api/worker/jobs/claim', 1700000000, 'abcdefghijklmnop', ''));
        self::assertNotSame($erwartet, WorkerProtokoll::signatur($schluessel, 'POST', '/api/worker/jobs/claim', 1700000000, 'abcdefghijklmnop', ' '));
    }

    public function testFreshNoncesMatchThePatternAndDiffer(): void
    {
        $a = WorkerProtokoll::neueNonce();
        $b = WorkerProtokoll::neueNonce();

        self::assertMatchesRegularExpression(WorkerProtokoll::NONCE_MUSTER, $a);
        self::assertNotSame($a, $b);
    }

    public function testTheFingerprintIsShownAsEightGroupsOfFour(): void
    {
        $fingerabdruck = WorkerProtokoll::fingerabdruck(str_repeat("\x02", 32));

        self::assertSame(strtoupper(hash('sha256', str_repeat("\x02", 32))), $fingerabdruck);
        self::assertMatchesRegularExpression('/^([0-9A-F]{4} ){7}[0-9A-F]{4}$/', WorkerProtokoll::fingerabdruckAnzeige($fingerabdruck));
    }

    public function testOnlyTheCurrentProtocolIsSupported(): void
    {
        self::assertTrue(WorkerProtokoll::unterstuetzt(WorkerProtokoll::VERSION));
        self::assertFalse(WorkerProtokoll::unterstuetzt(0));
        self::assertFalse(WorkerProtokoll::unterstuetzt(WorkerProtokoll::VERSION + 1));
    }
}
