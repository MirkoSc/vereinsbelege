<?php

declare(strict_types=1);

namespace App\Tests\Service\Integritaet;

use App\Service\Integritaet\Pruefsummenliste;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checksums.txt of a release as written by .github/workflows/release.yml
 * (issue #79/M13-2, docs/spec/01-sicherheit.md section 8).
 */
final class PruefsummenlisteTest extends TestCase
{
    public function testReadsTheFileLinesAndTheVersionAndSkipsTheAssets(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $liste = Pruefsummenliste::aus(
            str_repeat('1', 64) . "  setup.php\n"
            . str_repeat('2', 64) . "  vereinsbelege-v1.4.0-beta.2.zip\n"
            . strtoupper($b) . "  release/app/src/b.php\n"
            . $a . "  release/VERSION\n"
            . $a . "  release/app/x/mit leer.txt\n",
        );

        self::assertSame('1.4.0-beta.2', $liste->version);
        self::assertSame(
            ['VERSION' => $a, 'app/src/b.php' => $b, 'app/x/mit leer.txt' => $a],
            $liste->dateien,
            'sorted by path, lowercase hashes, the assets are not files of the release',
        );
    }

    /**
     * The format of sha256sum before this milestone: assets only - no file
     * may be expected then.
     */
    public function testAListWithoutFileLinesHasNoFiles(): void
    {
        $liste = Pruefsummenliste::aus(str_repeat('1', 64) . "  vereinsbelege-v1.0.0.zip\r\n" . str_repeat('2', 64) . " *setup.php\r\n");

        self::assertSame([], $liste->dateien);
        self::assertSame('1.0.0', $liste->version);
    }

    public function testTheFingerprintChangesWithTheContent(): void
    {
        $eins = Pruefsummenliste::aus(str_repeat('a', 64) . "  release/VERSION\n");
        $zwei = Pruefsummenliste::aus(str_repeat('b', 64) . "  release/VERSION\n");

        self::assertNotSame($eins->fingerabdruck, $zwei->fingerabdruck);
        self::assertSame($eins->fingerabdruck, Pruefsummenliste::aus(str_repeat('a', 64) . "  release/VERSION\n")->fingerabdruck);
    }

    /**
     * The list lies in shared/: a tampered one must not point the check at
     * files outside current/.
     */
    #[DataProvider('unsicherePfade')]
    public function testPathsLeavingTheReleaseRootAreDropped(string $pfad): void
    {
        $liste = Pruefsummenliste::aus(str_repeat('a', 64) . '  release/' . $pfad . "\n");

        self::assertSame([], $liste->dateien);
        self::assertFalse(Pruefsummenliste::sichererPfad($pfad));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsicherePfade(): iterable
    {
        yield 'parent' => ['../shared/config.php'];
        yield 'parent in the middle' => ['app/../../shared/config.php'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'dot segment' => ['./app/x.php'];
        yield 'empty segment' => ['app//x.php'];
        yield 'backslash' => ['app\\..\\x.php'];
        yield 'empty' => [''];
        yield 'nul' => ["app/x.php\0"];
    }

    public function testMalformedLinesAreIgnored(): void
    {
        $liste = Pruefsummenliste::aus(
            "kein hash  release/VERSION\n"
            . str_repeat('a', 63) . "  release/kurz\n"
            . '\\' . str_repeat('a', 64) . "  release/escaped\\nname\n"
            . str_repeat('c', 64) . "  release/app/ok.php\n",
        );

        self::assertSame(['app/ok.php' => str_repeat('c', 64)], $liste->dateien);
        self::assertNull($liste->version);
    }
}
