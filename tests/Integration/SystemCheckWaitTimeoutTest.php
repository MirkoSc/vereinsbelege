<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\SystemCheck\CheckStatus;
use App\Service\SystemCheck\SystemCheck;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #98, second half: the admin has to be able to SEE the server's
 * wait_timeout, because it decides how long an AI call may take.
 */
final class SystemCheckWaitTimeoutTest extends DatabaseTestCase
{
    public function testReadsTheServerValue(): void
    {
        $this->pdo()->exec('SET SESSION wait_timeout = 28800');

        $result = new SystemCheck($this->pdo())->waitTimeout($this->pdo());

        self::assertSame('wait_timeout', $result->key);
        self::assertSame('28800 s', $result->actual);
        self::assertSame(CheckStatus::Ok, $result->status);
    }

    /**
     * The host's own 120 s: a warning, not a failure - the application copes
     * via ConnectionFactory - but it must not disappear from the page.
     */
    public function testAShortTimeoutIsReportedAsAWarning(): void
    {
        $this->pdo()->exec('SET SESSION wait_timeout = 120');

        $result = new SystemCheck($this->pdo())->waitTimeout($this->pdo());

        self::assertSame('120 s', $result->actual);
        self::assertSame(CheckStatus::Warn, $result->status);
        self::assertStringContainsString('120', $result->detail);
    }

    public function testWithADatabaseBothChecksAreListed(): void
    {
        $keys = array_map(
            static fn($r): string => $r->key,
            new SystemCheck($this->pdo())->all(),
        );

        self::assertSame(['zend.exception_ignore_args', 'wait_timeout'], $keys);
    }
}
