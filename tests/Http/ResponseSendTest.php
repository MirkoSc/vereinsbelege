<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Cookie;
use App\Http\Session;
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
        // The dev container disables proc_open (like the host); CI runs this.
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available (disable_functions).');
        }

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
        if ($this->sessionDir === '') {
            return; // setUp skipped before creating anything
        }
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
        $name = Session::NAME_HTTP;
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
     * Security review N-14 (issue #246): over HTTPS the session cookie is
     * `__Host-sid` with exactly what the prefix demands - Secure, Path=/,
     * no Domain - and the regenerated id arrives under that name as well.
     */
    public function testOverHttpsTheSessionCookieCarriesTheHostPrefix(): void
    {
        $antwort = $this->request('/', https: true);
        $anonymous = $this->cookies($antwort);
        self::assertArrayHasKey(Session::NAME_HTTPS, $anonymous);
        self::assertArrayNotHasKey(Session::NAME_HTTP, $anonymous);

        $zeile = $this->setCookieZeile($antwort, Session::NAME_HTTPS);
        self::assertMatchesRegularExpression('/;\s*secure/i', $zeile);
        self::assertMatchesRegularExpression('/;\s*path=\/(;|$)/i', $zeile);
        self::assertDoesNotMatchRegularExpression('/;\s*domain=/i', $zeile);
        self::assertMatchesRegularExpression('/;\s*httponly/i', $zeile);
        self::assertMatchesRegularExpression('/;\s*samesite=Lax/i', $zeile);

        $cookies = $this->cookies($this->request('/anmelden', Session::NAME_HTTPS . '=' . $anonymous[Session::NAME_HTTPS], https: true));
        self::assertArrayHasKey(Session::NAME_HTTPS, $cookies);
        self::assertNotSame($anonymous[Session::NAME_HTTPS], $cookies[Session::NAME_HTTPS]);
        self::assertSame('vault-key', $cookies[SessionVault::COOKIE] ?? null);
    }

    /**
     * The switch after the update: a browser still sending PHPSESSID does
     * not get that session back over HTTPS (it would carry an id that was
     * never protected by the prefix) - it gets a fresh one, which means one
     * new login, and the old cookie is deleted instead of lingering.
     */
    public function testAnOldPhpsessidIsIgnoredAndDeletedOverHttps(): void
    {
        $alt = $this->cookies($this->request('/anmelden'))[Session::NAME_HTTP] ?? '';
        self::assertNotSame('', $alt, 'Testvorbereitung: eine angemeldete Session unter dem alten Namen.');

        $antwort = $this->request('/', Session::NAME_HTTP . '=' . $alt, https: true);
        $cookies = $this->cookies($antwort);

        self::assertArrayHasKey(Session::NAME_HTTPS, $cookies);
        self::assertNotSame($alt, $cookies[Session::NAME_HTTPS], 'Die alte Session wird nicht übernommen.');
        self::assertStringNotContainsString(
            'user_id',
            (string) file_get_contents($this->sessionDir . '/sess_' . $cookies[Session::NAME_HTTPS]),
        );
        $loeschen = $this->setCookieZeile($antwort, Session::NAME_HTTP);
        self::assertMatchesRegularExpression('/expires=Thu, 01[ -]Jan[ -]1970/i', $loeschen, 'PHPSESSID wird gelöscht.');
    }

    private function setCookieZeile(string $response, string $name): string
    {
        [$head] = explode("\r\n\r\n", $response, 2);
        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, 'Set-Cookie: ' . $name . '=') === 0) {
                return $line;
            }
        }
        self::fail('Kein Set-Cookie für ' . $name);
    }

    /**
     * Raw HTTP/1.0 so the answer comes back unchunked and the connection
     * closes on its own.
     */
    private function request(string $path, ?string $cookie = null, bool $https = false): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 5);
        self::assertIsResource($socket, 'Keine Verbindung zu php -S: ' . $error);

        $request = 'GET ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1\r\n";
        if ($https) {
            $request .= "X-Test-Https: 1\r\n";
        }
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
