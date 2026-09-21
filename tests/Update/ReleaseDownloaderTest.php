<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseDownloader;
use PHPUnit\Framework\TestCase;

final class ReleaseDownloaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                foreach (glob($file . '/*') ?: [] as $inner) {
                    unlink($inner);
                }
                rmdir($file);
            }
        }
    }

    private function temp(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/vb_test_' . uniqid('', true) . $suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseJson(string $tag, bool $prerelease, bool $draft = false): array
    {
        return [
            'tag_name' => $tag,
            'prerelease' => $prerelease,
            'draft' => $draft,
            'assets' => [
                [
                    'name' => 'vereinsbelege-' . $tag . '.zip',
                    'browser_download_url' => 'https://example.test/' . $tag . '.zip',
                ],
                [
                    'name' => 'checksums.txt',
                    'browser_download_url' => 'https://example.test/' . $tag . '-checksums.txt',
                ],
                ['name' => 'setup.php', 'browser_download_url' => 'https://example.test/setup.php'],
            ],
        ];
    }

    public function testStableChannelUsesReleasesLatest(): void
    {
        $requested = [];
        $downloader = new ReleaseDownloader(function (string $url) use (&$requested): string {
            $requested[] = $url;

            return json_encode(self::releaseJson('v1.2.0', false), JSON_THROW_ON_ERROR);
        });

        $release = $downloader->findLatestRelease('stable');

        self::assertNotNull($release);
        self::assertSame('1.2.0', $release['version']);
        self::assertStringEndsWith('/releases/latest', $requested[0]);
        self::assertStringContainsString('MirkoSc/vereinsbelege', $requested[0]);
        self::assertSame('https://example.test/v1.2.0.zip', $release['zip_url']);
        self::assertSame('https://example.test/v1.2.0-checksums.txt', $release['checksums_url']);
    }

    public function testBetaChannelTakesNewestIncludingPrereleasesButSkipsDrafts(): void
    {
        $downloader = new ReleaseDownloader(fn(string $url): string => json_encode([
            self::releaseJson('v1.3.0', false, draft: true),
            self::releaseJson('v1.3.0-rc1', true),
            self::releaseJson('v1.2.0', false),
        ], JSON_THROW_ON_ERROR));

        $release = $downloader->findLatestRelease('beta');

        self::assertNotNull($release);
        self::assertSame('1.3.0-rc1', $release['version'], 'newest non-draft, pre-releases included');
    }

    /**
     * Before the first release GitHub answers 404 on /releases/latest - a
     * normal state, not an error, and setup.php has to say so instead of
     * showing a stack trace.
     */
    public function testNoReleaseYetIsNotAnError(): void
    {
        $downloader = new ReleaseDownloader(function (string $url): string {
            throw new \RuntimeException('HTTP 404 für ' . $url);
        });

        self::assertNull($downloader->findLatestRelease('stable'));
    }

    public function testOtherHttpErrorsPropagate(): void
    {
        $downloader = new ReleaseDownloader(function (string $url): string {
            throw new \RuntimeException('HTTP 503 für ' . $url);
        });

        $this->expectException(\RuntimeException::class);
        $downloader->findLatestRelease('stable');
    }

    /**
     * A release whose ZIP is missing (or named differently, e.g. because it
     * still carries the calendar's asset name) must not be offered: the
     * updater would download nothing and switch to an empty directory.
     */
    public function testMissingAssetsMeansNoRelease(): void
    {
        $downloader = new ReleaseDownloader(fn(string $url): string => json_encode(
            ['tag_name' => 'v1.0.0', 'assets' => [
                ['name' => 'vereinskalender-v1.0.0.zip', 'browser_download_url' => 'https://example.test/x.zip'],
            ]],
            JSON_THROW_ON_ERROR,
        ));

        self::assertNull($downloader->findLatestRelease('stable'));
    }

    public function testChecksumVerificationAcceptsMatchingFile(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'test-inhalt');
        $checksums = hash('sha256', 'test-inhalt') . '  ' . basename($zip) . "\n";

        new ReleaseDownloader()->verifyChecksum($zip, $checksums);

        $this->addToAssertionCount(1); // no exception
    }

    public function testChecksumVerificationRejectsTamperedFile(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'manipulierter-inhalt');
        $checksums = hash('sha256', 'original-inhalt') . '  ' . basename($zip) . "\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prüfsummen-Fehler');

        new ReleaseDownloader()->verifyChecksum($zip, $checksums);
    }

    public function testChecksumVerificationRejectsMissingEntry(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'inhalt');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keine Prüfsumme');

        new ReleaseDownloader()->verifyChecksum($zip, "abc  andere-datei.zip\n");
    }

    public function testExtractRoundtrip(): void
    {
        $zipFile = $this->temp('.zip');
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('VERSION', "1.0.0\n");
        $zip->addFromString('app/test.txt', 'hallo');
        $zip->close();

        $target = $this->temp('_dir');
        new ReleaseDownloader()->extractTo($zipFile, $target);

        self::assertSame("1.0.0\n", file_get_contents($target . '/VERSION'));
        self::assertSame('hallo', file_get_contents($target . '/app/test.txt'));

        // manual cleanup of the nested dir for tearDown
        unlink($target . '/app/test.txt');
        rmdir($target . '/app');
    }

    /**
     * The local file must keep the asset name, otherwise verifyChecksum
     * never finds an entry in checksums.txt (that bug broke a fresh install
     * of the calendar once).
     */
    public function testZipFilenameKeepsAssetName(): void
    {
        self::assertSame(
            'vereinsbelege-v0.1.0.zip',
            ReleaseDownloader::zipFilename(
                'https://github.com/MirkoSc/vereinsbelege/releases/download/v0.1.0/vereinsbelege-v0.1.0.zip',
            ),
        );
        self::assertSame('release.zip', ReleaseDownloader::zipFilename('https://example.test/'));
    }

    /**
     * Local paths stay on the stream wrapper - curl does not serve them, and
     * this is the branch the tests themselves exercise.
     */
    public function testDownloadToCopiesLocalStream(): void
    {
        $source = $this->temp('.src');
        file_put_contents($source, 'release-daten');
        $target = $this->temp('.zip');

        new ReleaseDownloader()->downloadTo($source, $target);

        self::assertSame('release-daten', file_get_contents($target));
    }

    /**
     * The curl branch - the one the target host actually takes, and the one
     * no other test reaches, because a local path deliberately stays on the
     * stream wrapper. A refused connection on port 1 answers immediately and
     * still runs the whole path including the handle teardown: that is what
     * matters here, since PHP 8.5 deprecated curl_close() and the suite
     * fails on deprecations.
     */
    public function testTheCurlPathReportsAConnectionFailure(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-Anfrage fehlgeschlagen');

        new ReleaseDownloader()->fetchText('http://127.0.0.1:1/');
    }

    public function testTheCurlDownloadPathReportsAConnectionFailure(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not available');
        }

        $target = $this->temp('.zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download fehlgeschlagen');

        new ReleaseDownloader()->downloadTo('http://127.0.0.1:1/', $target);
    }

    public function testDownloadOfAnEmptyFileFails(): void
    {
        $source = $this->temp('.src');
        file_put_contents($source, '');
        $target = $this->temp('.zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download ist leer');

        new ReleaseDownloader()->downloadTo($source, $target);
    }
}
