<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Issue #240 (finding N-01): App\Repository\VaultRepository checks the
 * anchor of the vault public key only when it is given one - unit tests that
 * just seed rows build it without. The application must never do that by
 * accident: every `new VaultRepository(...)` in app/src and bin/ passes more
 * than the PDO, or says why not in a comment `anchor-exempt: <reason>`
 * directly above it (the installer's existence check and its delete, which
 * seal nothing).
 */
final class TresorAnkerPflichtTest extends TestCase
{
    public function testEveryVaultRepositoryOfTheApplicationIsAnchored(): void
    {
        $root = dirname(__DIR__, 2);
        $verstoesse = [];
        $gefunden = 0;
        foreach ($this->dateien($root) as $datei) {
            $quelle = (string) file_get_contents($datei);
            $zeilen = explode("\n", $quelle);
            foreach ($this->aufrufe($quelle) as [$zeile, $argumente]) {
                $gefunden++;
                if ($argumente > 1) {
                    continue;
                }
                $davor = trim($zeilen[$zeile - 2] ?? '');
                if (!str_contains($davor, 'anchor-exempt:')) {
                    $verstoesse[] = substr($datei, strlen($root) + 1) . ':' . $zeile;
                }
            }
        }

        self::assertGreaterThan(5, $gefunden, 'The scan found (almost) no VaultRepository - is it still looking in the right place?');
        self::assertSame([], $verstoesse, 'new VaultRepository() without the vault anchor (issue #240); pass $serverCrypto->tresorAnker() or explain with "anchor-exempt:"');
    }

    public function testTheScanCountsArgumentsAtTheTopLevelOnly(): void
    {
        $quelle = "<?php\n\$a = new VaultRepository(\$pdo);\n\$b = new VaultRepository(\$c->pdo(), f(\$x, \$y));\n\$d = new \\App\\Repository\\VaultRepository(g(\$e, \$f));\n";

        self::assertSame([[2, 1], [3, 2], [4, 1]], $this->aufrufe($quelle));
    }

    /**
     * @return list<string>
     */
    private function dateien(string $root): array
    {
        $dateien = [];
        foreach (['app/src', 'bin'] as $ordner) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $ordner, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $dateien[] = $file->getPathname();
                }
            }
        }
        sort($dateien);

        return $dateien;
    }

    /**
     * Every `new VaultRepository(` with its line and the number of
     * arguments at the top level of its parentheses.
     *
     * @return list<array{int, int}>
     */
    private function aufrufe(string $quelle): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($quelle),
            static fn(\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $treffer = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_NEW)) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if ($name === null || !$name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $teile = explode('\\', $name->text);
            if (end($teile) !== 'VaultRepository' || ($tokens[$i + 2] ?? null)?->text !== '(') {
                continue;
            }
            $tiefe = 0;
            $argumente = 0;
            for ($j = $i + 2; $j < count($tokens); $j++) {
                $text = $tokens[$j]->text;
                if (in_array($text, ['(', '[', '{'], true)) {
                    $tiefe++;
                    if ($tiefe === 1) {
                        $argumente = ($tokens[$j + 1] ?? null)?->text === ')' ? 0 : 1;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $tiefe--;
                    if ($tiefe === 0) {
                        break;
                    }
                } elseif ($text === ',' && $tiefe === 1 && ($tokens[$j + 1] ?? null)?->text !== ')') {
                    $argumente++;
                }
            }
            $treffer[] = [$name->line, $argumente];
        }

        return $treffer;
    }
}
