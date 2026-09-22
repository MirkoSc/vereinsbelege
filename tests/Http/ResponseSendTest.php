<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Cookie;
use App\Service\Account\SessionVault;
use PHPUnit\Framework\TestCase;

/**
 * The headers Response::send() really puts on the wire (issue #127).
 *
 * The flow tests call Kernel::handle() and read $_SESSION directly, so they
 * never see the Set-Cookie lines - and headers_list() is always empty on the
 * CLI. This test therefore runs the login's response under `php -S`
 * (tests/fixtures/http/login-response.php) and reads the raw answer: the
 * regenerated session id has to arrive next to the vault cookie, not be
 * replaced by it (docs/spec/01-sicherheit.md section 3,
 * "Session-ID-Regeneration bei Login").
 */
final class ResponseSendTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $sessionDir = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/response-send-' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700);

        $this->port = self::freePort();
        $router = dirname(__DIR__) . '/fixtures/http/login-response.php';
        $env = [...getenv(), 'RESPONSE_SEND_TEST_SESSION_DIR' => $this->sessionDir];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname($router),
            $env,
        );
        self::assertIsResource($process, 'php -S ließ sich nicht starten.');
        $this->server = $process;
        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->sessionDir)) {
            rmdir($this->sessionDir);
        }
    }

    public function testTheLoginResponseKeepsTheRegeneratedSessionCookieNextToTheOthers(): void
    {
        $anonymous = $this->cookies($this->request('/'));
        $name = session_name();
        self::assertArrayHasKey($name, $anonymous, 'Die Anmeldeseite startet eine Session.');

        $cookies = $this->cookies($this->request('/anmelden', $name . '=' . $anonymous[$name]));

        self::assertArrayHasKey($name, $cookies, 'Der regenerierte Session-Cookie fehlt in der Antwort.');
        self::assertNotSame($anonymous[$name], $cookies[$name], 'Die Session-ID wurde nicht erneuert.');
        self::assertSame('vault-key', $cookies[Cookie::INSECURE_NAME] ?? null);
        self::assertSame('device-token', $cookies['td'] ?? null, 'Der Geräte-Cookie kommt ebenfalls an.');
        self::assertArrayNotHasKey(SessionVault::COOKIE, $cookies, 'Über HTTP kein __Host-Präfix.');

        $stored = (string) file_get_contents($this->sessionDir . '/sess_' . $cookies[$name]);
        self::assertStringContainsString('user_id|i:42;', $stored, 'Die neue Session trägt die Anmeldung.');
    }

    /**
     * Raw HTTP/1.0 so the answer comes back unchunked and the connection
     * closes on its own.
     */
    private function request(string $path, ?string $cookie = null): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 5);
        self::assertIsResource($socket, 'Keine Verbindung zu php -S: ' . $error);

        $request = 'GET ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1\r\n";
        if ($cookie !== null) {
            $request .= 'Cookie: ' . $cookie . "\r\n";
        }
        fwrite($socket, $request . "\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        return $response;
    }

    /**
     * @return array<string, string> cookie name => value, from every
     *         Set-Cookie line of the response
     */
    private function cookies(string $response): array
    {
        [$head] = explode("\r\n\r\n", $response, 2);
        $cookies = [];
        foreach (explode("\r\n", $head) as $line) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m) === 1) {
                $cookies[$m[1]] = $m[2];
            }
        }

        return $cookies;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForServer(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('php -S ist nicht rechtzeitig erreichbar.');
    }
}
