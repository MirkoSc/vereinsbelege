<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md section 6a, docs/spec/07-worker.md section 1 (issue #47/M7-5):
 * the processing logic in app/src/Service/Processing/ runs identically in
 * the web app and in the optional worker, so it must not depend on Http,
 * Session, Repository or the database - the worker has none of them. This
 * test reads the source of every file there and refuses:
 *
 * - names in the namespaces of the web app's outer layers (App\Http,
 *   App\Repository, App\Database, App\Api, App\App, App\Admin,
 *   App\PublicPages, App\View, App\Installer),
 * - PDO and the mysqli functions,
 * - the request superglobals,
 * - calls of the session and header functions.
 *
 * App\Domain, App\Service\Crypto and App\Service\Ki (framework-free as
 * well) stay allowed.
 *
 * The worker program itself (worker/src/, App\Worker, issue #54) obeys the
 * same rule: it runs without the web app's request, session and database
 * and reaches the web host only through its signed API.
 */
final class ProcessingArchitectureTest extends TestCase
{
    private const array VERBOTENE_NAMENSRAEUME = [
        'app\\http\\',
        'app\\repository\\',
        'app\\database\\',
        'app\\api\\',
        'app\\app\\',
        'app\\admin\\',
        'app\\publicpages\\',
        'app\\view\\',
        'app\\installer\\',
    ];

    private const array VERBOTENE_KLASSEN = ['pdo', 'pdostatement', 'pdoexception', 'mysqli'];

    private const array VERBOTENE_VARIABLEN = ['$_SESSION', '$_GET', '$_POST', '$_COOKIE', '$_SERVER', '$_REQUEST', '$_FILES', '$GLOBALS'];

    private const array VERBOTENE_FUNKTIONEN = ['header', 'headers_sent', 'setcookie', 'setrawcookie', 'http_response_code'];

    /** @return iterable<string, array{string}> */
    public static function quelldateien(): iterable
    {
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/src/Service/Processing', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function workerDateien(): iterable
    {
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/worker/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('workerDateien')]
    public function testTheWorkerProgramDependsOnNoWebLayer(string $pfad): void
    {
        self::assertSame([], self::verstoesse((string) file_get_contents($pfad)), $pfad);
    }

    #[DataProvider('quelldateien')]
    public function testProcessingDependsOnNoWebLayer(string $pfad): void
    {
        self::assertSame([], self::verstoesse((string) file_get_contents($pfad)), $pfad);
    }

    public function testTheExtractionLogicIsAmongTheCheckedFiles(): void
    {
        $dateien = array_keys(iterator_to_array(self::quelldateien()));

        self::assertContains('app/src/Service/Processing/KiAuslese/Auslesung.php', $dateien);
    }

    public function testDetectorFindsWebDependenciesButNotLookalikes(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Service\Processing;
            use App\Http\Session;
            use App\Repository\DocumentRepository;
            use App\Domain\Category;
            use App\Service\Ki\KiPrompts;
            final class X {
                public function a(\PDO $pdo): void {
                    $x = $_SESSION['a'] ?? $_POST['b'];
                    session_start();
                    header('X-A: b');
                    \App\Database\ConnectionFactory::create();
                    $this->header('nicht gemeint');
                    $sessionStart = 'session_start';
                    new Category();
                }
            }
            PHP;

        self::assertSame(
            [
                'App\Http\Session',
                'App\Repository\DocumentRepository',
                '\PDO',
                '$_SESSION',
                '$_POST',
                'session_start()',
                'header()',
                '\App\Database\ConnectionFactory',
            ],
            self::verstoesse($code),
        );
    }

    /** @return list<string> */
    private static function verstoesse(string $code): array
    {
        $tokens = token_get_all($code);
        $gefunden = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            [$art, $text] = $token;

            if ($art === T_NAME_QUALIFIED || $art === T_NAME_FULLY_QUALIFIED) {
                $name = strtolower(ltrim($text, '\\'));
                foreach (self::VERBOTENE_NAMENSRAEUME as $praefix) {
                    if (str_starts_with($name, $praefix)) {
                        $gefunden[] = $text;
                        continue 2;
                    }
                }
                if (in_array($name, self::VERBOTENE_KLASSEN, true)) {
                    $gefunden[] = $text;
                }
                continue;
            }

            if ($art === T_VARIABLE && in_array($text, self::VERBOTENE_VARIABLEN, true)) {
                $gefunden[] = $text;
                continue;
            }

            if ($art !== T_STRING) {
                continue;
            }
            $name = strtolower($text);
            $vorher = self::nachbar($tokens, $i, -1);
            $istMitglied = is_array($vorher) && in_array($vorher[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            if (in_array($name, self::VERBOTENE_KLASSEN, true) && !$istMitglied) {
                $gefunden[] = $text;
                continue;
            }
            $istFunktion = (str_starts_with($name, 'session_') || str_starts_with($name, 'mysqli_') || in_array($name, self::VERBOTENE_FUNKTIONEN, true))
                && self::nachbar($tokens, $i, 1) === '('
                && !$istMitglied;
            if ($istFunktion) {
                $gefunden[] = $text . '()';
            }
        }

        return $gefunden;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private static function nachbar(array $tokens, int $i, int $schritt): array|string|null
    {
        for ($j = $i + $schritt; isset($tokens[$j]); $j += $schritt) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }
}
