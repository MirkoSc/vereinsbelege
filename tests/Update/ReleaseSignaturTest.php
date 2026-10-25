<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseSignatur;
use PHPUnit\Framework\TestCase;

/**
 * Release authenticity (issue #241, N-02/N-19; docs/spec/06-betrieb.md
 * section 1): Ed25519 over checksums.txt, the public key fixed in the code,
 * a warning instead of a refusal as long as none is fixed - plus the
 * maintainer tool, setup.php and the release workflow around it.
 */
final class ReleaseSignaturTest extends TestCase
{
    private const string LISTE = "abc  vereinsbelege-v1.2.3.zip\ndef  release/VERSION\n";

    /** @var list<string> */
    private array $dateien = [];

    protected function tearDown(): void
    {
        foreach ($this->dateien as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }
        putenv('RELEASE_SIGNING_KEY');
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function temp(string $suffix = ''): string
    {
        $pfad = sys_get_temp_dir() . '/vb_signatur_' . bin2hex(random_bytes(6)) . $suffix;
        $this->dateien[] = $pfad;
        $this->dateien[] = $pfad . '.sig';

        return $pfad;
    }

    public function testASignatureMadeWithTheSecretKeyIsValid(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $signatur = ReleaseSignatur::signiere(self::LISTE, $paar['secret']);

        self::assertNull(ReleaseSignatur::pruefe(self::LISTE, $signatur, $paar['public']));
        self::assertNull(ReleaseSignatur::pruefe(self::LISTE, "  " . $signatur . "\n", $paar['public']), 'as stored on disk');
        self::assertSame($paar['public'], ReleaseSignatur::publicKeyZu($paar['secret']));
        self::assertTrue(ReleaseSignatur::erzwungen($paar['public']));
    }

    /**
     * @return iterable<string, array{\Closure(array{public: string, secret: string}): array{string, ?string}}>
     */
    public static function falscheSignaturen(): iterable
    {
        yield 'list changed' => [static fn(array $p): array => [
            self::LISTE . "ghi  release/hintertuer.php\n",
            ReleaseSignatur::signiere(self::LISTE, $p['secret']),
        ]];
        yield 'other key' => [static fn(array $p): array => [
            self::LISTE,
            ReleaseSignatur::signiere(self::LISTE, ReleaseSignatur::erzeugeSchluesselpaar()['secret']),
        ]];
        yield 'missing' => [static fn(array $p): array => [self::LISTE, null]];
        yield 'not base64' => [static fn(array $p): array => [self::LISTE, '%%%']];
        yield 'too short' => [static fn(array $p): array => [self::LISTE, base64_encode('kurz')]];
        yield 'empty' => [static fn(array $p): array => [self::LISTE, '']];
    }

    /**
     * Once a key is fixed, nothing but a valid signature passes.
     *
     * @param \Closure(array{public: string, secret: string}): array{string, ?string} $fall
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('falscheSignaturen')]
    public function testWithAKeyEverythingButAValidSignatureIsRefused(\Closure $fall): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        [$liste, $signatur] = $fall($paar);

        $this->expectException(\RuntimeException::class);
        ReleaseSignatur::pruefe($liste, $signatur, $paar['public']);
    }

    /**
     * Before the maintainer fixes a key, releases keep working: a warning,
     * never a refusal - signed or not.
     */
    public function testWithoutAKeyThereIsAWarningInsteadOfARefusal(): void
    {
        self::assertStringContainsString('nicht signiert', (string) ReleaseSignatur::pruefe(self::LISTE, null, ''));
        self::assertStringContainsString(
            'kennt noch keinen Signaturschlüssel',
            (string) ReleaseSignatur::pruefe(self::LISTE, 'irgendwas', ''),
        );
        self::assertFalse(ReleaseSignatur::erzwungen(''));
    }

    public function testAMalformedFixedKeyIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Signaturschlüssel ist ungültig');

        ReleaseSignatur::pruefe(self::LISTE, null, 'kein-schluessel');
    }

    public function testAMalformedSecretIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RELEASE_SIGNING_KEY');

        ReleaseSignatur::signiere(self::LISTE, base64_encode(random_bytes(32)));
    }

    /**
     * The public key in the code is either empty or a valid Ed25519 key -
     * a typo there would make every installation refuse every release.
     */
    public function testTheFixedPublicKeyIsEmptyOrValid(): void
    {
        if (!ReleaseSignatur::erzwungen()) {
            self::assertSame('', ReleaseSignatur::PUBLIC_KEY);

            return;
        }
        $roh = base64_decode(ReleaseSignatur::PUBLIC_KEY, true);
        self::assertNotFalse($roh);
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($roh));
    }

    // ---- tools/release-signatur.php -------------------------------------

    private static function werkzeug(): void
    {
        require_once self::repoRoot() . '/tools/release-signatur.php';
    }

    public function testTheToolCreatesAKeyPairWithThePrivatePartOnlyInAFile(): void
    {
        self::werkzeug();
        $datei = $this->temp('.key');

        ob_start();
        $code = \release_signatur_schluessel($datei);
        $ausgabe = (string) ob_get_clean();

        self::assertSame(0, $code);
        $secret = trim((string) file_get_contents($datei));
        self::assertSame(0o600, fileperms($datei) & 0o777);
        $public = ReleaseSignatur::publicKeyZu($secret);
        self::assertStringContainsString($public, $ausgabe);
        self::assertStringNotContainsString($secret, $ausgabe, 'the secret never reaches the terminal');

        $this->expectException(\RuntimeException::class);
        \release_signatur_schluessel($datei);
    }

    /**
     * The release workflow without the secret: a warning and success, so a
     * release keeps working - as long as no public key is fixed.
     */
    public function testWithoutTheSecretTheToolOnlyWarns(): void
    {
        if (ReleaseSignatur::erzwungen()) {
            self::markTestSkipped('a public key is fixed - then a missing secret must fail the release');
        }
        self::werkzeug();
        $liste = $this->temp('.txt');
        file_put_contents($liste, self::LISTE);
        putenv('RELEASE_SIGNING_KEY=');

        ob_start();
        $code = \release_signatur_signieren($liste);
        $ausgabe = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('::warning::', $ausgabe);
        self::assertFileDoesNotExist($liste . '.sig');
    }

    public function testWithTheSecretTheToolWritesAValidSignature(): void
    {
        if (ReleaseSignatur::erzwungen()) {
            self::markTestSkipped('a public key is fixed - only its own secret may sign');
        }
        self::werkzeug();
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $liste = $this->temp('.txt');
        file_put_contents($liste, self::LISTE);
        putenv('RELEASE_SIGNING_KEY=' . $paar['secret']);

        ob_start();
        $code = \release_signatur_signieren($liste);
        ob_end_clean();

        self::assertSame(0, $code);
        self::assertNull(ReleaseSignatur::pruefe(self::LISTE, (string) file_get_contents($liste . '.sig'), $paar['public']));
    }

    // ---- setup.php and the release workflow ------------------------------

    /**
     * setup.php has no autoloader: the class is inlined, and the signature
     * is checked before the release is unpacked.
     */
    public function testSetupInlinesTheClassAndChecksBeforeUnpacking(): void
    {
        $setup = (string) file_get_contents(self::repoRoot() . '/setup.php');

        self::assertStringContainsString('final class ReleaseSignatur', $setup);
        self::assertStringContainsString('$signaturHinweis = ReleaseSignatur::pruefe($checksums, $signatur);', $setup);
        self::assertLessThan(
            strpos($setup, '$downloader->extractTo($zipFile, $target);'),
            strpos($setup, 'ReleaseSignatur::pruefe($checksums'),
        );
        self::assertStringContainsString('ReleaseDownloader::speichereListe($target, $checksums, $signatur);', $setup);
        self::assertStringNotContainsString("'/release_checksums.txt'", $setup, 'the list lives in the release now');
    }

    /**
     * Minimal rights (issue #241): the workflow is read-only by default,
     * only the publish job may write, and only it sees the secret; the
     * signing step runs before the upload.
     */
    public function testTheReleaseWorkflowKeepsTheSecretInThePublishJob(): void
    {
        $workflow = (string) file_get_contents(self::repoRoot() . '/.github/workflows/release.yml');

        self::assertMatchesRegularExpression('/^permissions:\n  contents: read\n/m', $workflow);
        $build = substr($workflow, (int) strpos($workflow, "\n  build:"), (int) strpos($workflow, "\n  publish:") - (int) strpos($workflow, "\n  build:"));
        $publish = substr($workflow, (int) strpos($workflow, "\n  publish:"));
        self::assertNotSame('', $build);
        self::assertStringNotContainsString('secrets.', $build);
        self::assertStringNotContainsString('contents: write', $build);
        self::assertStringContainsString('contents: write', $publish);
        self::assertSame(1, substr_count($workflow, 'secrets.'), 'only RELEASE_SIGNING_KEY, only once');
        self::assertStringContainsString('RELEASE_SIGNING_KEY: ${{ secrets.RELEASE_SIGNING_KEY }}', $publish);
        self::assertLessThan(
            strpos($publish, 'softprops/action-gh-release'),
            strpos($publish, 'php tools/release-signatur.php signieren dist/checksums.txt'),
        );
        self::assertStringContainsString('persist-credentials: false', $publish);
        // tools/ never goes into the release ZIP
        self::assertDoesNotMatchRegularExpression('#zip -r [^\n]*\btools\b#', $workflow);
    }
}
