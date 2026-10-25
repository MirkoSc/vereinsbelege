<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The boundary around the worker's process calls (docs/spec/07-worker.md
 * section 8, E-50). NoShellExecutionTest allows proc_open() in exactly one
 * file, worker/src/Prozess.php; this test makes sure that file can never
 * run on the web host:
 *
 *   - no code of the web app (app/src, app/views, public, setup.php) names
 *     anything of App\Worker - the worker uses the web app's code, never
 *     the other way round;
 *   - worker/ and bin/worker.php are not packed into the release ZIP, so
 *     the web host does not even receive them, and composer.json does not
 *     name App\Worker (worker/autoload.php) - the web host's classmap
 *     knows nothing of it;
 *   - Prozess passes an argument list to proc_open() (no shell).
 */
final class WorkerProzessGrenzeTest extends TestCase
{
    public function testTheWebAppNeverReferencesTheWorkerProgram(): void
    {
        $root = dirname(__DIR__, 2);
        $treffer = [];
        foreach (['app/src', 'app/views', 'public', 'migrations'] as $verzeichnis) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $verzeichnis, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $datei) {
                if ($datei instanceof \SplFileInfo && $datei->isFile() && $datei->getExtension() === 'php'
                    && preg_match('/App\\\\Worker\\\\/', (string) file_get_contents($datei->getPathname())) === 1) {
                    $treffer[] = substr($datei->getPathname(), strlen($root) + 1);
                }
            }
        }
        if (preg_match('/App\\\\Worker\\\\/', (string) file_get_contents($root . '/setup.php')) === 1) {
            $treffer[] = 'setup.php';
        }

        self::assertSame([], $treffer);
    }

    public function testTheWorkerProgramIsNotPartOfTheReleaseZip(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/release.yml');
        self::assertSame(1, preg_match('/zip -r "dist\/[^"]+" \\\\\s*\n\s*([^\n]+)/', $workflow, $treffer), 'release.yml packs the ZIP with zip -r');

        $inhalt = preg_split('/\s+/', trim($treffer[1])) ?: [];
        self::assertContains('app', $inhalt);
        self::assertNotContains('worker', $inhalt);
        // bin/ goes along for the web host's scripts - the worker's entry point not.
        self::assertStringContainsString('zip -d "dist/vereinsbelege-${GITHUB_REF_NAME}.zip" bin/worker.php', $workflow);
    }

    public function testComposerDoesNotKnowTheWorkerProgram(): void
    {
        self::assertStringNotContainsString('worker/', (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'));
    }

    public function testTheProcessClassPassesAnArgumentListWithoutShell(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/' . NoShellExecutionTest::PROZESS_DATEI);
        // The code without comments - the doc block talks about proc_open too.
        $code = '';
        foreach (token_get_all($quelle) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }

        self::assertSame(1, preg_match_all('/\bproc_open\s*\(/', $code));
        self::assertSame(1, preg_match('/\bproc_open\s*\(\s*array_values\(\$befehl\)/', $code));
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $code);
    }
}
