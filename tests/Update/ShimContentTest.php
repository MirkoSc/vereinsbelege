<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseSwitcher;
use PHPUnit\Framework\TestCase;

/**
 * The docroot shim exists in two places and must be byte-identical in both
 * (CLAUDE.md section 2):
 *
 *  - ReleaseSwitcher::SHIM  - what the updater self-heals to
 *  - docker/web/index.php   - what the dev environment runs AND what
 *                             setup.php copies into the docroot of a fresh
 *                             installation (from the unpacked release)
 *
 * Drift here is unusually expensive: the shim is the only file that answers
 * while current/ is mid-swap, it is not versioned, and a rollback cannot
 * restore it. A broken shim takes down the entire site INCLUDING /admin, so
 * there would be no way back through the application itself.
 */
final class ShimContentTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testDockerShimMatchesTheConstant(): void
    {
        self::assertSame(
            ReleaseSwitcher::SHIM,
            file_get_contents(self::repoRoot() . '/docker/web/index.php'),
            'docker/web/index.php drifted from ReleaseSwitcher::SHIM',
        );
    }

    /**
     * setup.php writes the docroot by COPYING these three files out of the
     * release it just unpacked, which is what keeps a fresh installation
     * from drifting away from the repository. If that ever turned into an
     * embedded copy again, the shim would have a third source of truth.
     */
    public function testSetupCopiesTheDocrootFilesFromTheRelease(): void
    {
        $setup = (string) file_get_contents(self::repoRoot() . '/setup.php');

        self::assertStringContainsString("['index.php', '.htaccess', '.user.ini']", $setup);
        self::assertStringContainsString('$target . \'/docker/web/\' . $datei', $setup);
        self::assertStringNotContainsString(
            'Docroot shim - written by setup.php',
            $setup,
            'setup.php must copy the shim, not carry its own copy',
        );
    }

    /**
     * The generated file has to be in sync with its template and the shared
     * downloader - CI runs bin/build_setup.php and diffs, but a stale
     * setup.php is what a fresh installation actually runs, so it is worth
     * asserting here too.
     */
    public function testGeneratedSetupIsFresh(): void
    {
        $setup = (string) file_get_contents(self::repoRoot() . '/setup.php');
        $template = (string) file_get_contents(self::repoRoot() . '/bin/setup.template.php');
        $downloader = (string) file_get_contents(
            self::repoRoot() . '/app/src/Service/Update/ReleaseDownloader.php',
        );

        self::assertStringNotContainsString('//__SHARED_CODE__', $setup, 'placeholder not replaced');
        self::assertStringContainsString(
            'final class ReleaseDownloader',
            $setup,
            'setup.php must carry the inlined downloader - it has no autoloader',
        );

        // A marker from each input, so an edit to either one without
        // re-running bin/build_setup.php is caught locally and not only in CI.
        self::assertStringContainsString('Vereinsbelege einrichten', $template);
        self::assertStringContainsString('Vereinsbelege einrichten', $setup);
        $muster = 'public const string ZIP_PATTERN = \'/^vereinsbelege-.+\.zip$/\';';
        self::assertStringContainsString($muster, $downloader);
        self::assertStringContainsString($muster, $setup);
    }

    /**
     * A syntax error in the shim would be catastrophic and is invisible to
     * every other check: the string is never linted, and exec() is not
     * available on the target hosting, so nothing can shell out to `php -l`.
     * token_get_all() with TOKEN_PARSE runs the real parser in-process and
     * throws ParseError on invalid code.
     */
    public function testShimIsSyntacticallyValidPhp(): void
    {
        token_get_all(ReleaseSwitcher::SHIM, \TOKEN_PARSE);

        self::assertStringStartsWith('<?php', ReleaseSwitcher::SHIM);
        self::assertStringEndsWith("\n", ReleaseSwitcher::SHIM, 'must end with a newline');
    }

    /**
     * The two conditions the shim exists for. Pinned as strings because both
     * are easy to drop by accident while "tidying up" the file, and neither
     * failure is visible until an update is already in flight.
     */
    public function testShimGuardsBothMissingReleaseAndMaintenanceFlag(): void
    {
        self::assertStringContainsString('!is_file($release)', ReleaseSwitcher::SHIM);
        self::assertStringContainsString('shared/maintenance.flag', ReleaseSwitcher::SHIM);
        self::assertStringContainsString("str_starts_with(\$pfad, '/admin')", ReleaseSwitcher::SHIM);
        self::assertStringContainsString('http_response_code(503)', ReleaseSwitcher::SHIM);
    }

    /**
     * The admin page is what ends a maintenance window - by finishing the
     * update, by rolling it back, or by the button that clears a flag a
     * crashed update left behind. Its stylesheet and its script have to get
     * through too, otherwise that page arrives unstyled and the step buttons
     * do nothing. Both copies of the check are pinned: the shim answers
     * while current/ is mid-swap, public/index.php afterwards.
     */
    public function testTheAdminPageKeepsItsAssetsDuringMaintenance(): void
    {
        $frontController = (string) file_get_contents(self::repoRoot() . '/public/index.php');

        foreach ([ReleaseSwitcher::SHIM, $frontController] as $quelle) {
            self::assertStringContainsString("'/css/'", $quelle);
            self::assertStringContainsString("'/js/'", $quelle);
        }
    }

    /**
     * Security review N-11: plain HTTP is redirected to HTTPS - but only for
     * GET/HEAD, never for /cron (a hoster's cron may only speak HTTP), never
     * for local hosts, IP addresses or another port than 80, and with a kill
     * switch in shared/ against a loop behind an unusual proxy - a 302, so
     * the switch is not undone by a browser cache. Pinned as strings like the conditions above:
     * the shim cannot be run in-process (it ends with exit and require).
     */
    public function testPlainHttpIsRedirectedExceptForCronAndPosts(): void
    {
        $shim = ReleaseSwitcher::SHIM;

        foreach ([
            "header('Location: https://' . \$teile[1] . (\$_SERVER['REQUEST_URI'] ?? '/'), true, 302);",
            "in_array(\$_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)",
            "rtrim(\$pfad, '/') !== '/cron'",
            "in_array(\$teile[2] ?? '', ['', '80'], true)",
            "strtolower(\$teile[1]) !== 'localhost'",
            "filter_var(\$teile[1], FILTER_VALIDATE_IP) === false",
            "shared/https_umleitung_aus.flag",
            "(string) (\$_SERVER['SERVER_PORT'] ?? '') !== '443'",
            "strtolower((string) (\$_SERVER['REQUEST_SCHEME'] ?? '')) !== 'https'",
        ] as $muss) {
            self::assertStringContainsString($muss, $shim);
        }
        // X-Forwarded-Proto may only switch the redirect off - a negated
        // check, never something that sends a request elsewhere.
        self::assertSame(1, substr_count($shim, 'HTTP_X_FORWARDED_PROTO'));
        self::assertStringContainsString("!str_contains(strtolower((string) (\$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')), 'https')", $shim);
        // The redirect comes before the maintenance page, so an http:// visit
        // during an update ends on https:// too.
        self::assertLessThan(strpos($shim, 'http_response_code(503)'), strpos($shim, "'Location: https://'"));
    }

    /**
     * The host for the redirect comes from the client. It is taken only in
     * the shape of a host name (no slash, no @, no space), so the redirect
     * cannot send anyone to a path or userinfo of another site.
     */
    public function testTheRedirectHostPatternTakesHostNamesOnly(): void
    {
        self::assertSame(1, preg_match("~preg_match\\('(/\\^.+\\$/D)'~", ReleaseSwitcher::SHIM, $treffer));
        $muster = $treffer[1];

        foreach (['verein.example' => ['verein.example', ''], 'belege.verein.example:80' => ['belege.verein.example', '80'], 'localhost:8080' => ['localhost', '8080']] as $host => [$name, $port]) {
            self::assertSame(1, preg_match($muster, $host, $teile), $host);
            self::assertSame($name, $teile[1]);
            self::assertSame($port, $teile[2] ?? '');
        }
        foreach (['evil.example/x', 'a@evil.example', 'evil.example\r\nX: 1', '', '.example', 'verein..example', 'verein.example:', "verein.example\n"] as $host) {
            self::assertSame(0, preg_match($muster, $host), $host);
        }
    }

    /**
     * The shim runs before the application's own bootstrap and must not do
     * anything that needs it - no autoloader, no config, no database.
     */
    public function testShimNeedsNothingButItself(): void
    {
        foreach (['vendor/autoload', 'App\\', 'PDO', 'require_once'] as $verboten) {
            self::assertStringNotContainsString($verboten, ReleaseSwitcher::SHIM);
        }
    }
}
