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

    public function testTheSignatureAssetIsOptional(): void
    {
        $mit = self::releaseJson('v1.2.0', false);
        $mit['assets'][] = [
            'name' => 'checksums.txt.sig',
            'browser_download_url' => 'https://example.test/v1.2.0-checksums.txt.sig',
        ];

        $release = new ReleaseDownloader(fn(string $url): string => json_encode($mit, JSON_THROW_ON_ERROR))
            ->findLatestRelease('stable');
        self::assertNotNull($release);
        self::assertSame('https://example.test/v1.2.0-checksums.txt.sig', $release['signatur_url']);

        $ohne = new ReleaseDownloader(fn(string $url): string => json_encode(
            self::releaseJson('v1.2.0', false),
            JSON_THROW_ON_ERROR,
        ))->findLatestRelease('stable');
        self::assertNotNull($ohne, 'a release without signature stays installable (older releases, no secret)');
        self::assertNull($ohne['signatur_url']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function verboteneAdressen(): iterable
    {
        yield 'local path' => ['/tmp/vereinsbelege.zip'];
        yield 'file url' => ['file:///etc/passwd'];
        yield 'plain http' => ['http://github.com/MirkoSc/vereinsbelege/releases/download/v1/x.zip'];
        yield 'foreign host' => ['https://example.test/x.zip'];
        yield 'look-alike host' => ['https://github.com.example.test/x.zip'];
        yield 'other port' => ['https://github.com:8443/x.zip'];
        yield 'credentials' => ['https://user:pass@github.com/x.zip'];
        yield 'ftp' => ['ftp://github.com/x.zip'];
        // any GitHub user can publish releases on the same hosts
        yield 'other repository' => ['https://github.com/attacker/vereinsbelege/releases/download/v9/x.zip'];
        yield 'other repository via the api' => ['https://api.github.com/repos/attacker/x/releases/latest'];
        yield 'not a release asset' => ['https://github.com/MirkoSc/vereinsbelege/archive/main.zip'];
        yield 'dot segments' => ['https://github.com/MirkoSc/vereinsbelege/releases/download/../../../attacker/x/y.zip'];
        yield 'encoded path' => ['https://github.com/MirkoSc/vereinsbelege/releases/download/%2e%2e/x.zip'];
        // the storage hosts are reached by GitHub's redirect only
        yield 'storage host as start' => ['https://objects.githubusercontent.com/x.zip'];
    }

    /**
     * N-18 (issue #241): the asset URLs pass through
     * shared/update_state.json - whoever can edit it must not be able to
     * point the updater elsewhere.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verboteneAdressen')]
    public function testDownloadsAreLimitedToGithubOverHttps(string $url): void
    {
        $aufgerufen = false;
        $downloader = new ReleaseDownloader(
            function (string $url) use (&$aufgerufen): string {
                $aufgerufen = true;

                return 'x';
            },
            function (string $url, string $ziel) use (&$aufgerufen): void {
                $aufgerufen = true;
                file_put_contents($ziel, 'x');
            },
        );

        foreach ([
            fn() => $downloader->downloadTo($url, $this->temp('.zip')),
            fn() => $downloader->fetchText($url),
        ] as $aufruf) {
            try {
                $aufruf();
                self::fail('accepted: ' . $url);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('nicht erlaubt', $e->getMessage());
            }
        }
        self::assertFalse($aufgerufen, 'refused before any request');
    }

    public function testGithubHostsAreAllowed(): void
    {
        foreach ([
            'https://api.github.com/repos/MirkoSc/vereinsbelege/releases/latest',
            'https://github.com/MirkoSc/vereinsbelege/releases/download/v1.0.0/vereinsbelege-v1.0.0.zip',
            'https://objects.githubusercontent.com/github-production-release-asset/1?X-Amz=1',
            'https://release-assets.githubusercontent.com/github-production-release-asset/1?sp=r',
            'HTTPS://GitHub.com:443/x',
        ] as $url) {
            ReleaseDownloader::pruefeUrl($url);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Every hop is checked, not just the first URL: GitHub redirects the
     * asset download to its storage host, and a redirect must not lead off
     * GitHub or down to plain http.
     */
    public function testEveryRedirectHopIsChecked(): void
    {
        $besucht = [];
        $kette = [
            'https://github.com/MirkoSc/vereinsbelege/releases/download/v1/x.zip' =>
                'https://release-assets.githubusercontent.com/asset/1?sig=a',
            'https://release-assets.githubusercontent.com/asset/1?sig=a' => '/asset/2',
            'https://release-assets.githubusercontent.com/asset/2' => null,
        ];
        ReleaseDownloader::folgeWeiterleitungen(
            'https://github.com/MirkoSc/vereinsbelege/releases/download/v1/x.zip',
            ReleaseDownloader::ERLAUBTE_HOSTS,
            function (string $url) use (&$besucht, $kette): ?string {
                $besucht[] = $url;

                return $kette[$url];
            },
        );
        self::assertSame(array_keys($kette), $besucht, 'a host-relative Location stays on the same host');

        foreach ([
            'https://evil.example/x.zip',
            'http://objects.githubusercontent.com/x.zip',
            'file:///etc/passwd',
            '//evil.example/x.zip',
        ] as $ziel) {
            $besucht = [];
            try {
                ReleaseDownloader::folgeWeiterleitungen(
                    'https://github.com/x.zip',
                    ReleaseDownloader::ERLAUBTE_HOSTS,
                    function (string $url) use (&$besucht, $ziel): ?string {
                        $besucht[] = $url;

                        return $ziel;
                    },
                );
                self::fail('redirect accepted: ' . $ziel);
            } catch (\RuntimeException) {
                self::assertSame(['https://github.com/x.zip'], $besucht, 'the forbidden hop is never requested');
            }
        }
    }

    public function testRedirectLoopsEnd(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zu viele Weiterleitungen');

        ReleaseDownloader::folgeWeiterleitungen(
            'https://github.com/a',
            ReleaseDownloader::ERLAUBTE_HOSTS,
            fn(string $url): string => 'https://github.com/a',
        );
    }

    public function testListAndSignatureGoIntoTheReleaseDirectory(): void
    {
        $dir = $this->temp('_release');
        mkdir($dir);

        ReleaseDownloader::speichereListe($dir, "liste\n", "c2lnbmF0dXI=\n");
        self::assertSame("liste\n", file_get_contents($dir . '/checksums.txt'));
        self::assertSame("c2lnbmF0dXI=\n", file_get_contents($dir . '/checksums.txt.sig'));

        ReleaseDownloader::speichereListe($dir, "andere\n", null);
        self::assertSame("andere\n", file_get_contents($dir . '/checksums.txt'));
        self::assertFileDoesNotExist($dir . '/checksums.txt.sig', 'no stale signature next to another list');
    }

    /**
     * The curl branch - the one the target host actually takes. A refused
     * connection answers immediately and still runs the whole path including
     * the handle teardown: that is what matters here, since PHP 8.5
     * deprecated curl_close() and the suite fails on deprecations. The host
     * list is widened for the test only; nothing listens on 127.0.0.1:443.
     */
    public function testTheCurlPathReportsAConnectionFailure(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-Anfrage fehlgeschlagen');

        new ReleaseDownloader(erlaubteHosts: ['127.0.0.1'])->fetchText('https://127.0.0.1/');
    }

    public function testTheCurlDownloadPathReportsAConnectionFailure(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not available');
        }

        $target = $this->temp('.zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download fehlgeschlagen');

        new ReleaseDownloader(erlaubteHosts: ['127.0.0.1'])->downloadTo('https://127.0.0.1/', $target);
    }

    /**
     * The self-test fetches the installation's own start page - the only
     * request not limited to GitHub.
     */
    public function testTheSelfTestIsNotLimitedToGithub(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-Anfrage fehlgeschlagen');

        new ReleaseDownloader()->fetchSelbsttest('http://127.0.0.1:1/');
    }

    public function testDownloadOfAnEmptyFileFails(): void
    {
        $target = $this->temp('.zip');
        $downloader = new ReleaseDownloader(download: function (string $url, string $ziel): void {
            file_put_contents($ziel, '');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download ist leer');

        $downloader->downloadTo('https://github.com/MirkoSc/vereinsbelege/releases/download/v1/x.zip', $target);
    }
}
