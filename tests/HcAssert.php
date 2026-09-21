<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The assertion vocabulary the hosting-check cases were written against,
 * forwarded to PHPUnit.
 *
 * It exists so the ~40 probe cases in HostingCheckTest could move from the
 * standalone runner to PHPUnit without being rewritten - every assertion in
 * them is unchanged, which is the point: the conversion must not quietly
 * alter what is being checked about a script that gets uploaded to a live
 * webspace.
 */
final readonly class HcAssert
{
    public function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertSame($expected, $actual, $message);
    }

    public function true(bool $condition, string $message): void
    {
        TestCase::assertTrue($condition, $message);
    }

    public function contains(string $needle, string $haystack, string $message = ''): void
    {
        TestCase::assertStringContainsString($needle, $haystack, $message);
    }

    public function notContains(string $needle, string $haystack, string $message = ''): void
    {
        TestCase::assertStringNotContainsString($needle, $haystack, $message);
    }

    /**
     * For the probes that need something the run does not have - currently
     * only the database checks, which want a real server via HC_TEST_DB_*.
     * The standalone runner just returned early there and counted the case as
     * passed; PHPUnit calls a case without assertions risky, and rightly so.
     */
    public function skip(string $message): never
    {
        TestCase::markTestSkipped($message);
    }
}
