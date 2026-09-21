<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Shared release download/verify/extract logic (docs/spec/06-betrieb.md
 * section 1): used by the self-updater AND inlined into setup.php by
 * bin/build_setup.php - keep this class dependency-free (no App\ imports,
 * no autoloader), because inside setup.php it is the only class there is.
 *
 * The download URL is hardwired to the project repository and never comes
 * from user input.
 */
final class ReleaseDownloader
{
    public const string REPO = 'MirkoSc/vereinsbelege';

    /**
     * Matches the asset the release workflow builds
     * (vereinsbelege-v1.2.3.zip). A pattern rather than an exact name so a
     * release can carry other assets - setup.php is one of them.
     */
    public const string ZIP_PATTERN = '/^vereinsbelege-.+\.zip$/';

    /** @var \Closure(string): string */
    private readonly \Closure $httpGet;

    /**
     * @param (\Closure(string): string)|null $httpGet override for tests
     */
    public function __construct(?\Closure $httpGet = null)
    {
        $this->httpGet = $httpGet ?? self::defaultHttpGet(...);
    }

    /**
     * Channel 'stable' uses /releases/latest (GitHub excludes pre-releases
     * there); 'beta' takes the newest release including pre-releases.
     *
     * @return array{version: string, zip_url: string, checksums_url: string}|null
     */
    public function findLatestRelease(string $channel = 'stable'): ?array
    {
        $base = 'https://api.github.com/repos/' . self::REPO;

        try {
            if ($channel === 'beta') {
                $releases = json_decode(($this->httpGet)($base . '/releases?per_page=10'), true);
                foreach (is_array($releases) ? $releases : [] as $release) {
                    if (is_array($release) && ($release['draft'] ?? false) !== true) {
                        $info = self::releaseInfo($release);
                        if ($info !== null) {
                            return $info;
                        }
                    }
                }

                return null;
            }

            $release = json_decode(($this->httpGet)($base . '/releases/latest'), true);

            return is_array($release) ? self::releaseInfo($release) : null;
        } catch (\RuntimeException $e) {
            // no (regular) release yet: GitHub answers 404 on /releases/latest
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    public function fetchText(string $url): string
    {
        return ($this->httpGet)($url);
    }

    /**
     * Local filename for a downloaded release ZIP. MUST be the original
     * asset name: verifyChecksum() matches checksums.txt entries by
     * basename, so an arbitrary local name would never match.
     */
    public static function zipFilename(string $zipUrl): string
    {
        $name = basename((string) parse_url($zipUrl, PHP_URL_PATH));

        return $name !== '' ? $name : 'release.zip';
    }

    /**
     * Streams the (possibly large) ZIP to disk without loading it into
     * memory; GitHub asset URLs redirect, both transports follow that.
     */
    public function downloadTo(string $url, string $targetFile): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $target = fopen($targetFile, 'wb');
        if ($target === false) {
            throw new \RuntimeException('Zieldatei nicht beschreibbar: ' . $targetFile);
        }

        try {
            if (self::useCurl($url)) {
                self::curlInto($url, $target);
            } else {
                $source = @fopen($url, 'rb', context: self::streamContext());
                if ($source === false) {
                    throw new \RuntimeException('Download fehlgeschlagen: ' . $url);
                }
                stream_copy_to_stream($source, $target);
                fclose($source);
            }
        } finally {
            fclose($target);
        }

        if (filesize($targetFile) === 0) {
            throw new \RuntimeException('Download ist leer: ' . $url);
        }
    }

    /**
     * checksums.txt format: "<sha256>  <filename>" per line (sha256sum).
     */
    public function verifyChecksum(string $zipFile, string $checksumsContent): void
    {
        $expected = null;
        $basename = basename($zipFile);
        foreach (preg_split('/\r\n|\n|\r/', $checksumsContent) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m) === 1
                && trim($m[2]) === $basename) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            throw new \RuntimeException('Keine Prüfsumme für ' . $basename . ' in checksums.txt gefunden.');
        }

        $actual = hash_file('sha256', $zipFile);
        if ($actual === false || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('Prüfsummen-Fehler: das heruntergeladene ZIP ist beschädigt oder manipuliert.');
        }
    }

    public function extractTo(string $zipFile, string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('ZIP kann nicht geöffnet werden: ' . $zipFile);
        }
        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new \RuntimeException('ZIP kann nicht entpackt werden nach: ' . $targetDir);
        }
        $zip->close();
    }

    /**
     * @param array<string, mixed> $release
     * @return array{version: string, zip_url: string, checksums_url: string}|null
     */
    private static function releaseInfo(array $release): ?array
    {
        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $zipUrl = null;
        $checksumsUrl = null;

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (preg_match(self::ZIP_PATTERN, $name) === 1) {
                $zipUrl = $url;
            } elseif ($name === 'checksums.txt') {
                $checksumsUrl = $url;
            }
        }

        if ($version === '' || $zipUrl === null || $checksumsUrl === null) {
            return null;
        }

        return ['version' => $version, 'zip_url' => $zipUrl, 'checksums_url' => $checksumsUrl];
    }

    /**
     * curl first, the stream wrapper as fallback.
     *
     * The M0 hosting check confirmed ext-curl and a working HTTPS call to
     * api.github.com; allow_url_fopen is NOT among the recorded findings,
     * and shared hosts do switch it off. Deciding per call instead of once
     * keeps local paths and file:// URLs on the stream wrapper, which curl
     * would not serve.
     */
    private static function useCurl(string $url): bool
    {
        return function_exists('curl_init') && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * @param resource $target
     */
    private static function curlInto(string $url, $target): void
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Download fehlgeschlagen: ' . $url);
        }

        curl_setopt_array($handle, [
            CURLOPT_FILE => $target,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Generous: a release ZIP carries vendor/ and is a few MB, and a
            // slow shared host is the normal case here, not the exception.
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Vereinsbelege-Updater',
            CURLOPT_FAILONERROR => true,
        ]);

        $ok = curl_exec($handle);
        $fehler = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): deprecated since PHP 8.5 and without effect since
        // 8.0 - the handle is freed when it goes out of scope.

        if ($ok === false) {
            throw new \RuntimeException(sprintf(
                'Download fehlgeschlagen (%s): %s',
                $status > 0 ? 'HTTP ' . $status : 'Verbindungsfehler',
                $fehler !== '' ? $fehler : $url,
            ));
        }
    }

    private static function defaultHttpGet(string $url): string
    {
        if (self::useCurl($url)) {
            return self::curlGet($url);
        }

        $body = @file_get_contents($url, false, self::streamContext());
        if ($body === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $url);
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m) === 1 && (int) $m[1] >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d für %s', (int) $m[1], $url));
        }

        return $body;
    }

    private static function curlGet(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $url);
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Vereinsbelege-Updater',
        ]);

        $body = curl_exec($handle);
        $fehler = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(), see curlInto().

        if ($body === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . ($fehler !== '' ? $fehler : $url));
        }
        // The message shape matters: findLatestRelease() recognises "no
        // release yet" by the "HTTP 404" in it.
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d für %s', $status, $url));
        }

        return (string) $body;
    }

    /**
     * @return resource
     */
    private static function streamContext()
    {
        return stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'Vereinsbelege-Updater',
                'ignore_errors' => true,
            ],
        ]);
    }
}
