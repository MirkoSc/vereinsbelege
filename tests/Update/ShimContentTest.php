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
