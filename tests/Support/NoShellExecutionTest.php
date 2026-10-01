<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md section 1: no process calls from PHP. The host does not block them
 * (disable_functions is empty there), so this test guards the self-restriction.
 */
final class NoShellExecutionTest extends TestCase
{
    private const FORBIDDEN = ['exec', 'shell_exec', 'proc_open', 'passthru', 'system', 'popen', 'pcntl_exec'];

    /** @return iterable<string, array{string}> */
    public static function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (['app/src', 'app/views', 'bin', 'migrations'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
                }
            }
        }
        yield 'setup.php' => [$root . '/setup.php'];
    }

    #[DataProvider('sourceFiles')]
    public function testFileContainsNoProcessCalls(string $path): void
    {
        if (!is_file($path)) {
            self::markTestSkipped('File not present.');
        }
        self::assertSame([], self::violations((string) file_get_contents($path)), $path);
    }

    public function testDetectorFindsCallsButNotMethods(): void
    {
        $code = '<?php $a = exec("x"); $b = \\shell_exec("y"); $c = `ls`; $pdo->exec("z"); Foo::system(); '
            . 'function popen() {} new Exec();';
        self::assertSame(['exec', 'shell_exec', 'backtick'], self::violations($code));
    }

    /** @return list<string> */
    private static function violations(string $code): array
    {
        $tokens = token_get_all($code);
        $found = [];
        $count = count($tokens);
        $inBackticks = false;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '`') {
                $inBackticks = !$inBackticks;
                if ($inBackticks) {
                    $found[] = 'backtick';
                }
                continue;
            }
            if (!is_array($t) || $t[0] !== T_STRING && $t[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $name = ltrim(strtolower($t[1]), '\\');
            if (!in_array($name, self::FORBIDDEN, true)) {
                continue;
            }
            $prev = self::neighbour($tokens, $i, -1);
            $next = self::neighbour($tokens, $i, 1);
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
            if ($next === '(' && !$isMember) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private static function neighbour(array $tokens, int $i, int $step): array|string|null
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }
}
