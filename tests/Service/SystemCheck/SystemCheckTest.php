<?php

declare(strict_types=1);

namespace App\Tests\Service\SystemCheck;

use App\Service\SystemCheck\CheckStatus;
use App\Service\SystemCheck\SystemCheck;
use PHPUnit\Framework\TestCase;

final class SystemCheckTest extends TestCase
{
    public function testExceptionIgnoreArgsIsOkWhenTheSettingIsOn(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $result = new SystemCheck()->exceptionIgnoreArgs();

            self::assertSame(CheckStatus::Ok, $result->status);
            self::assertSame('On', $result->actual);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /**
     * A failure, not a warning: with the setting off every trace can carry a
     * password, a vault key or decrypted receipt data (issue #97).
     */
    public function testExceptionIgnoreArgsFailsWhenTheSettingIsOff(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $result = new SystemCheck()->exceptionIgnoreArgs();

            self::assertSame(CheckStatus::Fail, $result->status);
            self::assertSame('Off', $result->actual);
            self::assertStringContainsString('Bootstrap', $result->detail);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testWithoutADatabaseOnlyTheIniCheckRuns(): void
    {
        $keys = array_map(
            static fn($r): string => $r->key,
            new SystemCheck()->all(),
        );

        self::assertSame(['zend.exception_ignore_args'], $keys);
    }

    public function testWorstStatusWins(): void
    {
        self::assertSame(CheckStatus::Ok, CheckStatus::worst(CheckStatus::Ok, CheckStatus::Ok));
        self::assertSame(CheckStatus::Warn, CheckStatus::worst(CheckStatus::Ok, CheckStatus::Warn));
        self::assertSame(CheckStatus::Fail, CheckStatus::worst(CheckStatus::Warn, CheckStatus::Fail));
        self::assertSame(CheckStatus::Ok, CheckStatus::worst());
    }

    /**
     * The check result is rendered in the admin area and may end up in a
     * support mail, so it must stay free of anything but settings.
     */
    public function testResultsCarryNoBusinessData(): void
    {
        $result = new SystemCheck()->exceptionIgnoreArgs();

        self::assertSame('zend.exception_ignore_args', $result->key);
        self::assertArrayHasKey('status', $result->toArray());
        self::assertSame($result->status->value, $result->toArray()['status']);
    }
}
