<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Http\Zugriff;
use App\Support\FileLogger;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md section 4: no business plaintext and no passwords in logs. The
 * global error handler must never write the full exception string - a stack
 * trace carries function arguments when zend.exception_ignore_args is off, so
 * a failing login would otherwise log the plaintext password, and a failing
 * processing step the decrypted receipt.
 *
 * Both tests force the ini setting OFF on purpose: the guarantee has to come
 * from the handler, not from the setting issue #97 also fixes.
 */
final class KernelErrorLogTest extends TestCase
{
    private static function viewsDir(): string
    {
        return dirname(__DIR__, 2) . '/app/views';
    }

    /**
     * Builds a route whose handler throws with a secret travelling as a call
     * argument - exactly like a real controller -> service chain.
     */
    private static function routerLeakingArgument(string $secret): Router
    {
        $router = new Router();
        $router->post('/anmelden', Zugriff::oeffentlich(), static function (Request $r, array $p) use ($secret): ResponseInterface {
            $attempt = static function (string $email, string $password): ResponseInterface {
                throw new \RuntimeException('database unavailable');
            };

            return $attempt('kasse@example.org', $secret);
        });

        return $router;
    }

    public function testErrorLogNeverContainsFunctionArgumentsLikePasswords(): void
    {
        $previousIgnore = ini_set('zend.exception_ignore_args', '0');
        $logFile = tempnam(sys_get_temp_dir(), 'kernel_errlog_');
        $previousLog = ini_set('error_log', $logFile);

        try {
            $secret = 'sup3r-secret-passw0rd';

            $kernel = new Kernel(
                self::routerLeakingArgument($secret),
                new StaticFileHandler(sys_get_temp_dir(), longCache: false),
                new View(self::viewsDir(), '0.0.0-test'),
                debug: false,
            );

            $response = $kernel->handle(new Request(HttpMethod::Post, '/anmelden', ip: '203.0.113.9'));

            self::assertSame(500, $response->status);
            self::assertStringNotContainsString($secret, $response->body);

            $logged = (string) file_get_contents($logFile);
            self::assertNotSame('', $logged, 'the error was logged');
            self::assertStringNotContainsString($secret, $logged, 'no plaintext password in the log');
            self::assertStringContainsString('RuntimeException', $logged);
            self::assertStringContainsString('database unavailable', $logged);
            self::assertStringContainsString('POST', $logged);
            self::assertStringContainsString('/anmelden', $logged);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            ini_set('zend.exception_ignore_args', $previousIgnore === false ? '1' : $previousIgnore);
            @unlink($logFile);
        }
    }

    /**
     * The same line also goes to shared/var/log/, because error_log() lands in
     * the provider's PHP log, which is not reliably readable from the customer
     * panel. That copy is the one an admin actually gets to read, so a leaked
     * secret there would be worse, not better.
     */
    public function testTheFileLogGetsTheSameRedactedLine(): void
    {
        $previousIgnore = ini_set('zend.exception_ignore_args', '0');
        $appLog = sys_get_temp_dir() . '/vb_kernel_' . uniqid('', true) . '/var/log/app.log';
        // error_log() fires as well; redirect it so it does not print into
        // the test output
        $errLog = tempnam(sys_get_temp_dir(), 'kernel_errlog_');
        $previousLog = ini_set('error_log', $errLog);

        try {
            $secret = 'sup3r-secret-passw0rd';

            $kernel = new Kernel(
                self::routerLeakingArgument($secret),
                new StaticFileHandler(sys_get_temp_dir(), longCache: false),
                new View(self::viewsDir(), '0.0.0-test'),
                debug: false,
                logger: new FileLogger($appLog),
            );

            $kernel->handle(new Request(HttpMethod::Post, '/anmelden', ip: '203.0.113.9'));

            $logged = (string) file_get_contents($appLog);
            self::assertStringNotContainsString($secret, $logged, 'no plaintext password in the file log');
            self::assertStringContainsString('RuntimeException', $logged);
            self::assertStringContainsString('database unavailable', $logged);
            self::assertStringContainsString('/anmelden', $logged);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            ini_set('zend.exception_ignore_args', $previousIgnore === false ? '1' : $previousIgnore);
            @unlink($errLog);
            @unlink($appLog);
            @rmdir(dirname($appLog));
            @rmdir(dirname($appLog, 2));
            @rmdir(dirname($appLog, 3));
        }
    }

    /**
     * debug: true renders the exception string into the browser, which is why
     * it is off everywhere but the dev container - pinned here so nobody
     * flips the default.
     */
    public function testDebugOffKeepsTheExceptionOutOfTheResponse(): void
    {
        $errLog = tempnam(sys_get_temp_dir(), 'kernel_errlog_');
        $previousLog = ini_set('error_log', $errLog);

        try {
            $router = new Router();
            $router->get('/kaputt', Zugriff::oeffentlich(), static function (): ResponseInterface {
                throw new \RuntimeException('interne Details');
            });

            $kernel = new Kernel(
                $router,
                new StaticFileHandler(sys_get_temp_dir(), longCache: false),
                new View(self::viewsDir(), '0.0.0-test'),
                debug: false,
            );

            $response = $kernel->handle(new Request(HttpMethod::Get, '/kaputt'));

            self::assertSame(500, $response->status);
            self::assertStringNotContainsString('interne Details', $response->body);
            self::assertStringNotContainsString('RuntimeException', $response->body);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($errLog);
        }
    }
}
