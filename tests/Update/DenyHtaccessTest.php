<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\DenyHtaccess;
use PHPUnit\Framework\TestCase;

/**
 * The deny-all .htaccess for shared/, releases/ and - via the copy at the
 * root of every release - current/ (issue #101, docs/spec/06-betrieb.md
 * section 1).
 */
final class DenyHtaccessTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/vb_deny_' . bin2hex(random_bytes(6));
        mkdir($this->base, 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Apache 2.4 gets "Require all denied", 2.2 the Order/Deny pair - each
     * inside the IfModule branch for the module that understands it, so
     * neither server stumbles over the other's directive.
     */
    public function testTheContentDeniesOnApache24AndFallsBackForApache22(): void
    {
        $content = DenyHtaccess::CONTENT;

        self::assertMatchesRegularExpression(
            '#<IfModule mod_authz_core\.c>\s*Require all denied\s*</IfModule>#',
            $content,
        );
        self::assertMatchesRegularExpression(
            '#<IfModule !mod_authz_core\.c>\s*Order allow,deny\s*Deny from all\s*</IfModule>#',
            $content,
        );
        self::assertStringNotContainsString('Allow from', $content);
        self::assertStringNotContainsString('Require all granted', $content);
        self::assertStringEndsWith("\n", $content);
        self::assertTrue(DenyHtaccess::denies($content));
    }

    /**
     * The repository root is the release root: its .htaccess travels in the
     * release ZIP and locks current/, which no installer step can do for
     * good - current/ is replaced by a renamed release on every update.
     */
    public function testTheReleaseRootCarriesTheSameFile(): void
    {
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents(self::repoRoot() . '/.htaccess'));

        $workflow = (string) file_get_contents(self::repoRoot() . '/.github/workflows/release.yml');
        self::assertMatchesRegularExpression('#zip -r [^\n]*\\\\\n\s+[^\n]*\s\.htaccess\s*\n#', $workflow);
    }

    public function testEnsureWritesTheFile(): void
    {
        self::assertTrue(DenyHtaccess::ensure($this->base));

        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/.htaccess'));
    }

    public function testEnsureIsIdempotent(): void
    {
        DenyHtaccess::ensure($this->base);

        self::assertTrue(DenyHtaccess::ensure($this->base));
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/.htaccess'));
    }

    /**
     * A hand-written file is never overwritten. It counts as protection only
     * when it denies access itself.
     */
    public function testAnExistingFileIsKeptAndJudgedByItsOwnRules(): void
    {
        $eigene = "# eigene Sperre\nRequire all denied\n";
        file_put_contents($this->base . '/.htaccess', $eigene);

        self::assertTrue(DenyHtaccess::ensure($this->base));
        self::assertSame($eigene, file_get_contents($this->base . '/.htaccess'));

        $offen = "Options -Indexes\n# Require all denied\n";
        file_put_contents($this->base . '/.htaccess', $offen);

        self::assertFalse(DenyHtaccess::ensure($this->base), 'a commented-out rule protects nothing');
        self::assertSame($offen, file_get_contents($this->base . '/.htaccess'));
    }

    public function testAMissingDirectoryIsReportedAsUnprotected(): void
    {
        self::assertFalse(DenyHtaccess::ensure($this->base . '/gibt-es-nicht'));
    }

    public function testEnsureAllCoversSharedAndReleasesAndSkipsWhatDoesNotExist(): void
    {
        mkdir($this->base . '/shared');
        mkdir($this->base . '/releases');
        mkdir($this->base . '/web');
        mkdir($this->base . '/current');

        self::assertSame([], DenyHtaccess::ensureAll($this->base));

        self::assertFileExists($this->base . '/shared/.htaccess');
        self::assertFileExists($this->base . '/releases/.htaccess');
        self::assertFileDoesNotExist($this->base . '/web/.htaccess', 'the docroot must stay public');
        self::assertFileDoesNotExist($this->base . '/current/.htaccess', 'current/ brings its own');

        self::removeTree($this->base . '/releases');
        self::assertSame([], DenyHtaccess::ensureAll($this->base), 'nothing to protect yet');
    }

    /**
     * The installer does not stop when the file cannot be written - it
     * needs the names to warn. A directory in the file's place stands in for
     * "not writable", which a test running as root could not produce with
     * chmod.
     */
    public function testEnsureAllNamesTheDirectoriesItCouldNotProtect(): void
    {
        mkdir($this->base . '/shared/.htaccess', 0775, true);
        mkdir($this->base . '/releases');

        self::assertSame(['shared/'], DenyHtaccess::ensureAll($this->base));
        self::assertFileExists($this->base . '/releases/.htaccess');
    }

    /**
     * setup.php has no autoloader: the class is inlined by
     * bin/build_setup.php, and the installer writes the files before the
     * release lands in releases/.
     */
    public function testSetupCarriesTheClassAndUsesIt(): void
    {
        $setup = (string) file_get_contents(self::repoRoot() . '/setup.php');

        self::assertStringContainsString('final class DenyHtaccess', $setup);
        $klasse = (string) file_get_contents(self::repoRoot() . '/app/src/Service/Update/DenyHtaccess.php');
        $rumpf = trim(substr($klasse, (int) strpos($klasse, '/**')));
        self::assertStringContainsString($rumpf, $setup, 'setup.php is stale - run bin/build_setup.php');
        self::assertStringContainsString('$ungeschuetzt = DenyHtaccess::ensureAll($rootDir);', $setup);
        self::assertLessThan(
            strpos($setup, '$downloader->downloadTo('),
            strpos($setup, 'DenyHtaccess::ensureAll($rootDir)'),
            'the lock comes before anything is downloaded into releases/',
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
