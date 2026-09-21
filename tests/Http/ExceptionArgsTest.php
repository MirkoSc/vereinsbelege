<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Issue #97: the target host ships zend.exception_ignore_args = 0, so
 * function ARGUMENTS end up in every stack trace. On this application that
 * means a plaintext password, an unwrapped vault key or decrypted receipt
 * data, in any trace that is rendered or logged.
 *
 * The acceptance criterion is literally "raise an exception from a function
 * with an argument and prove the argument is not in the trace", so that is
 * what this does - against the real ini setting, not a mock.
 */
final class ExceptionArgsTest extends TestCase
{
    /**
     * Short on purpose: getTraceAsString() truncates each argument to 15
     * characters, so a longer secret would never appear in full and the
     * control case below could not tell "redacted" from "shortened".
     */
    private const string SECRET = 'hunter2-secret';

    private static function entrypoints(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function throwWith(string $password): never
    {
        throw new \RuntimeException('etwas ging schief');
    }

    private static function traceFor(string $password): string
    {
        try {
            self::throwWith($password);
        } catch (\RuntimeException $e) {
            return $e->getTraceAsString();
        }
    }

    /**
     * Runs $fn with both trace-related ini settings pinned, and restores
     * them afterwards.
     *
     * The second one matters as much as the first and is easy to miss:
     * zend.exception_string_param_max_len caps how much of a string
     * argument a trace shows, and php.ini-production sets it to 0, which
     * replaces every string parameter with '...'. Leaving it to the host
     * would make these tests pass or fail depending on whose PHP runs them -
     * CI ships 0, the docker image does not - and the control case below
     * would silently stop proving anything.
     *
     * @param \Closure(): void $fn
     */
    private static function withIni(string $ignoreArgs, string $paramLen, \Closure $fn): void
    {
        $vorherIgnore = ini_set('zend.exception_ignore_args', $ignoreArgs);
        $vorherLen = ini_set('zend.exception_string_param_max_len', $paramLen);

        try {
            $fn();
        } finally {
            ini_set('zend.exception_ignore_args', $vorherIgnore === false ? '1' : $vorherIgnore);
            ini_set('zend.exception_string_param_max_len', $vorherLen === false ? '0' : $vorherLen);
        }
    }

    /**
     * The control case: with the setting off, the secret IS in the trace.
     * Without this the test below would also pass on a PHP build that simply
     * never records arguments, and would prove nothing.
     */
    public function testWithoutTheSettingTheArgumentLeaksIntoTheTrace(): void
    {
        self::withIni('0', '15', static function (): void {
            self::assertStringContainsString(self::SECRET, self::traceFor(self::SECRET));
        });
    }

    /**
     * The same generous parameter length as the control case, so a failure
     * here means the argument was dropped - not merely shortened.
     */
    public function testWithTheSettingTheArgumentIsGone(): void
    {
        self::withIni('1', '15', static function (): void {
            $trace = self::traceFor(self::SECRET);

            self::assertStringNotContainsString(self::SECRET, $trace);
            self::assertStringContainsString('throwWith', $trace, 'the frame itself is still there');
        });
    }

    /**
     * The belt to the first setting's braces, and the one the release ships
     * as well: even with arguments kept, a string parameter is cut to '...'.
     * Pinned separately because the two settings fail independently.
     */
    public function testTheParameterLengthCapAloneAlsoHidesTheArgument(): void
    {
        self::withIni('0', '0', static function (): void {
            self::assertStringNotContainsString(self::SECRET, self::traceFor(self::SECRET));
        });
    }

    /**
     * The setting is PHP_INI_ALL, which is the whole reason the runtime fix
     * works on a host that ships it off. If a future PHP were to make it
     * PHP_INI_PERDIR, bootstrap.php would silently stop protecting anything -
     * so the property is pinned rather than assumed.
     */
    public function testTheSettingIsChangeableAtRuntime(): void
    {
        $previous = ini_get('zend.exception_ignore_args');

        try {
            self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
            self::assertNotFalse(ini_set('zend.exception_ignore_args', '1'));
            self::assertSame('1', ini_get('zend.exception_ignore_args'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /**
     * Every entry point has to set it, and each for a different window:
     * public/index.php covers the require of bootstrap.php itself,
     * bootstrap.php covers the web application, bin/migrate.php the CLI, and
     * tests/bootstrap.php this very test suite.
     *
     * A grep test, because the alternative is a subprocess per entry point
     * for a property that is one line long and easy to drop in a refactor.
     */
    public function testEveryEntrypointSetsItBeforeAnythingElseCanThrow(): void
    {
        $fehlend = [];
        foreach ([
            'public/index.php',
            'app/src/bootstrap.php',
            'bin/migrate.php',
            'tests/bootstrap.php',
        ] as $datei) {
            $inhalt = (string) file_get_contents(self::entrypoints() . '/' . $datei);
            if (!str_contains($inhalt, "ini_set('zend.exception_ignore_args', '1')")) {
                $fehlend[] = $datei;
            }
            if (!str_contains($inhalt, "ini_set('zend.exception_string_param_max_len', '0')")) {
                $fehlend[] = $datei . ' (param length cap)';
            }
        }

        self::assertSame([], $fehlend, 'entry points without the ini_set from issue #97');
    }

    /**
     * The net for the window before any PHP of ours runs: a parse error in
     * the autoloader still produces a trace. .user.ini and not .htaccess
     * because the host runs fpm-fcgi, where php_value in .htaccess answers
     * with a 500.
     */
    public function testTheReleaseShipsAUserIniWithTheValue(): void
    {
        $userIni = self::entrypoints() . '/docker/web/.user.ini';

        self::assertFileExists($userIni);
        $inhalt = (string) file_get_contents($userIni);
        self::assertMatchesRegularExpression('/^\s*zend\.exception_ignore_args\s*=\s*On\s*$/mi', $inhalt);
        self::assertMatchesRegularExpression(
            '/^\s*zend\.exception_string_param_max_len\s*=\s*0\s*$/mi',
            $inhalt,
        );
    }
}
