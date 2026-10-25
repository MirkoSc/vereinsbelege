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
 * from user input. Every request of the update - including each redirect
 * hop - is limited to https and the GitHub hosts in ERLAUBTE_HOSTS (issue
 * #241, N-18): the asset URLs pass through shared/update_state.json, which
 * whoever has FTP access can edit, and a redirect must not lead off GitHub
 * either. Only the self-test of the updater fetches the installation's own
 * start page (fetchSelbsttest()).
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

    /**
     * Hosts the updater and setup.php may talk to. GitHub serves release
     * assets from github.com and redirects the download to a storage host:
     * objects.githubusercontent.com, since 2025 also
     * release-assets.githubusercontent.com.
     */
    public const array ERLAUBTE_HOSTS = [
        'api.github.com',
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /**
     * Name of the checksums list inside an installed release directory
     * (issue #241): setup.php and the updater put the release's
     * checksums.txt - and its signature as ReleaseSignatur::ASSET - into the
     * release root, so the list moves with every rename() of the release,
     * rollback included. The release ZIP itself never contains these names.
     */
    public const string LISTE = 'checksums.txt';

    /** Redirect hops followed per request (GitHub needs one). */
    public const int MAX_WEITERLEITUNGEN = 5;

    /** @var \Closure(string): string */
    private readonly \Closure $httpGet;

    /** @var \Closure(string): string */
    private readonly \Closure $selbsttestGet;

    /** @var \Closure(string, string): void */
    private readonly \Closure $download;

    /**
     * @param (\Closure(string): string)|null $httpGet override for tests;
     *        also answers the self-test then
     * @param (\Closure(string, string): void)|null $download override for
     *        tests: (url, target file)
     * @param list<string> $erlaubteHosts override for tests
     */
    public function __construct(
        ?\Closure $httpGet = null,
        ?\Closure $download = null,
        private readonly array $erlaubteHosts = self::ERLAUBTE_HOSTS,
    ) {
        $this->httpGet = $httpGet ?? fn(string $url): string => self::githubGet($url, $this->erlaubteHosts);
        $this->selbsttestGet = $httpGet ?? self::defaultHttpGet(...);
        $this->download = $download ?? function (string $url, string $targetFile): void {
            self::githubDownload($url, $targetFile, $this->erlaubteHosts);
        };
    }

    /**
     * Channel 'stable' uses /releases/latest (GitHub excludes pre-releases
     * there); 'beta' takes the newest release including pre-releases.
     *
     * @return array{version: string, zip_url: string, checksums_url: string, signatur_url: string|null}|null
     */
    public function findLatestRelease(string $channel = 'stable'): ?array
    {
        $base = 'https://api.github.com/repos/' . self::REPO;

        try {
            if ($channel === 'beta') {
                $releases = json_decode($this->fetchText($base . '/releases?per_page=10'), true);
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

            $release = json_decode($this->fetchText($base . '/releases/latest'), true);

            return is_array($release) ? self::releaseInfo($release) : null;
        } catch (\RuntimeException $e) {
            // no (regular) release yet: GitHub answers 404 on /releases/latest
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * A small text from GitHub (API answer, checksums.txt, signature).
     */
    public function fetchText(string $url): string
    {
        $this->pruefeQuelle($url);

        return ($this->httpGet)($url);
    }

    /**
     * The updater's self-test: the installation's own start page. The only
     * request not limited to GitHub - the base URL is the installation's.
     */
    public function fetchSelbsttest(string $url): string
    {
        return ($this->selbsttestGet)($url);
    }

    /**
     * Throws unless $url is https to one of $hosts, without credentials and
     * on the default port. Called for the first URL and for every redirect
     * hop.
     *
     * @param list<string> $hosts
     */
    public static function pruefeUrl(string $url, array $hosts = self::ERLAUBTE_HOSTS): void
    {
        $teile = parse_url($url);
        $schema = is_array($teile) ? strtolower((string) ($teile['scheme'] ?? '')) : '';
        $host = is_array($teile) ? strtolower((string) ($teile['host'] ?? '')) : '';
        if ($schema !== 'https'
            || !in_array($host, $hosts, true)
            || isset($teile['user'])
            || isset($teile['pass'])
            || (isset($teile['port']) && (int) $teile['port'] !== 443)) {
            throw new \RuntimeException(sprintf(
                'Download-Adresse nicht erlaubt (nur https zu GitHub): %s://%s',
                $schema !== '' ? $schema : '?',
                $host !== '' ? $host : '?',
            ));
        }
    }

    /**
     * The first URL of a request must name THIS repository: the API under
     * /repos/MirkoSc/vereinsbelege/, an asset under
     * /MirkoSc/vereinsbelege/releases/download/ - any GitHub user can
     * publish releases on the same hosts. The storage hosts are only
     * reached by GitHub's own redirect, never as a starting point. Hosts a
     * test allows besides GitHub's are not subject to this.
     */
    private function pruefeQuelle(string $url): void
    {
        self::pruefeUrl($url, $this->erlaubteHosts);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, self::ERLAUBTE_HOSTS, true)) {
            return;
        }
        $pfad = strtolower((string) parse_url($url, PHP_URL_PATH));
        $repo = strtolower(self::REPO);
        $erlaubt = match ($host) {
            'api.github.com' => str_starts_with($pfad, '/repos/' . $repo . '/'),
            'github.com' => str_starts_with($pfad, '/' . $repo . '/releases/download/'),
            default => false,
        };
        if (!$erlaubt || str_contains($pfad, '/../') || str_contains($pfad, '%')) {
            throw new \RuntimeException('Download-Adresse nicht erlaubt (nur Releases von ' . self::REPO . ').');
        }
    }

    /**
     * Follows redirects by hand so pruefeUrl() sees every hop - curl's
     * CURLOPT_FOLLOWLOCATION and the stream wrapper's follow_location would
     * go anywhere. $hop performs ONE request and returns the Location of a
     * redirect answer, or null for the final answer.
     *
     * @param list<string> $hosts
     * @param \Closure(string): ?string $hop
     */
    public static function folgeWeiterleitungen(string $url, array $hosts, \Closure $hop): void
    {
        for ($weiterleitungen = 0; ; $weiterleitungen++) {
            self::pruefeUrl($url, $hosts);
            $ziel = $hop($url);
            if ($ziel === null) {
                return;
            }
            if ($weiterleitungen >= self::MAX_WEITERLEITUNGEN) {
                throw new \RuntimeException('Zu viele Weiterleitungen beim Download.');
            }
            $url = self::aufgeloest($url, $ziel);
        }
    }

    /**
     * A Location header made absolute: absolute URLs as they are,
     * host-relative ones ("/pfad") against the current scheme and host.
     * Anything else (path-relative, protocol-relative) is not what GitHub
     * sends and is refused.
     */
    private static function aufgeloest(string $basis, string $ziel): string
    {
        $ziel = trim($ziel);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ziel) === 1) {
            return $ziel;
        }
        if (str_starts_with($ziel, '/') && !str_starts_with($ziel, '//')) {
            $teile = parse_url($basis);

            return strtolower((string) ($teile['scheme'] ?? '')) . '://' . (string) ($teile['host'] ?? '') . $ziel;
        }

        throw new \RuntimeException('Ungültige Weiterleitung beim Download.');
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
     * memory; GitHub asset URLs redirect, every hop is checked.
     */
    public function downloadTo(string $url, string $targetFile): void
    {
        $this->pruefeQuelle($url);

        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        ($this->download)($url, $targetFile);

        clearstatcache(true, $targetFile);
        if (!is_file($targetFile) || filesize($targetFile) === 0) {
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

    /**
     * Puts the checksums list and its signature into a release directory
     * (see LISTE). Without a signature a stale signature file is removed,
     * so list and signature never belong to different releases.
     */
    public static function speichereListe(string $releaseDir, string $liste, ?string $signatur): void
    {
        if (file_put_contents($releaseDir . '/' . self::LISTE, $liste, LOCK_EX) === false) {
            throw new \RuntimeException('Prüfsummenliste kann nicht gespeichert werden.');
        }
        $signaturDatei = $releaseDir . '/' . ReleaseSignatur::ASSET;
        if ($signatur === null) {
            if (is_file($signaturDatei)) {
                unlink($signaturDatei);
            }

            return;
        }
        if (file_put_contents($signaturDatei, trim($signatur) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Signatur der Prüfsummenliste kann nicht gespeichert werden.');
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
     * @return array{version: string, zip_url: string, checksums_url: string, signatur_url: string|null}|null
     */
    private static function releaseInfo(array $release): ?array
    {
        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $zipUrl = null;
        $checksumsUrl = null;
        // Optional: releases before issue #241, and releases built without
        // the RELEASE_SIGNING_KEY secret, carry no signature.
        $signaturUrl = null;

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
            } elseif ($name === 'checksums.txt.sig') {
                $signaturUrl = $url;
            }
        }

        if ($version === '' || $zipUrl === null || $checksumsUrl === null) {
            return null;
        }

        return [
            'version' => $version,
            'zip_url' => $zipUrl,
            'checksums_url' => $checksumsUrl,
            'signatur_url' => $signaturUrl,
        ];
    }

    /**
     * curl first, the stream wrapper as fallback - for the GitHub transports
     * below as well (they ask function_exists('curl_init') directly).
     *
     * The M0 hosting check confirmed ext-curl and a working HTTPS call to
     * api.github.com; allow_url_fopen is NOT among the recorded findings,
     * and shared hosts do switch it off. For the self-test, deciding per
     * call keeps a non-http base URL on the stream wrapper.
     */
    private static function useCurl(string $url): bool
    {
        return function_exists('curl_init') && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * GET of a small text from GitHub, redirects checked hop by hop.
     *
     * @param list<string> $hosts
     */
    private static function githubGet(string $url, array $hosts): string
    {
        $body = '';
        $status = 0;
        $letzte = $url;
        self::folgeWeiterleitungen($url, $hosts, function (string $hopUrl) use (&$body, &$status, &$letzte): ?string {
            $letzte = $hopUrl;
            [$status, $location, $body] = function_exists('curl_init')
                ? self::curlHop($hopUrl, null)
                : self::streamHop($hopUrl, null);

            return self::istWeiterleitung($status) ? self::ziel($location) : null;
        });
        // The message shape matters: findLatestRelease() recognises "no
        // release yet" by the "HTTP 404" in it.
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d für %s', $status, $letzte));
        }

        return (string) $body;
    }

    /**
     * Download into a file, redirects checked hop by hop. The body of a
     * redirect answer is discarded before the next hop.
     *
     * @param list<string> $hosts
     */
    private static function githubDownload(string $url, string $targetFile, array $hosts): void
    {
        $target = fopen($targetFile, 'wb');
        if ($target === false) {
            throw new \RuntimeException('Zieldatei nicht beschreibbar: ' . $targetFile);
        }

        try {
            self::folgeWeiterleitungen($url, $hosts, function (string $hopUrl) use ($target): ?string {
                ftruncate($target, 0);
                rewind($target);
                [$status, $location] = function_exists('curl_init')
                    ? self::curlHop($hopUrl, $target)
                    : self::streamHop($hopUrl, $target);
                if (self::istWeiterleitung($status)) {
                    return self::ziel($location);
                }
                if ($status >= 400) {
                    throw new \RuntimeException(sprintf('Download fehlgeschlagen (HTTP %d)', $status));
                }

                return null;
            });
        } finally {
            fclose($target);
        }
    }

    private static function istWeiterleitung(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /**
     * A redirect without Location is an error - never the final answer,
     * its body is no release file.
     */
    private static function ziel(?string $location): string
    {
        if ($location === null || trim($location) === '') {
            throw new \RuntimeException('Weiterleitung ohne Ziel beim Download.');
        }

        return $location;
    }

    /**
     * One request with curl, redirects NOT followed.
     *
     * @param resource|null $target stream for the body, or null to return it
     * @return array{0: int, 1: string|null, 2: string} status, Location, body
     */
    private static function curlHop(string $url, $target): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException($target === null ? 'HTTP-Anfrage fehlgeschlagen: ' . $url : 'Download fehlgeschlagen: ' . $url);
        }

        $optionen = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Generous for the ZIP: it carries vendor/ and is a few MB, and a
            // slow shared host is the normal case here, not the exception.
            CURLOPT_TIMEOUT => $target === null ? 20 : 120,
            CURLOPT_USERAGENT => 'Vereinsbelege-Updater',
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $optionen[CURLOPT_PROTOCOLS_STR] = 'https';
        }
        if ($target === null) {
            $optionen[CURLOPT_RETURNTRANSFER] = true;
        } else {
            $optionen[CURLOPT_FILE] = $target;
        }
        curl_setopt_array($handle, $optionen);

        $ergebnis = curl_exec($handle);
        $fehler = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
        // No curl_close(): deprecated since PHP 8.5 and without effect since
        // 8.0 - the handle is freed when it goes out of scope.

        if ($ergebnis === false) {
            throw new \RuntimeException(sprintf(
                '%s (%s): %s',
                $target === null ? 'HTTP-Anfrage fehlgeschlagen' : 'Download fehlgeschlagen',
                $status > 0 ? 'HTTP ' . $status : 'Verbindungsfehler',
                $fehler !== '' ? $fehler : $url,
            ));
        }

        return [
            $status,
            is_string($location) && $location !== '' ? $location : null,
            $target === null ? (string) $ergebnis : '',
        ];
    }

    /**
     * One request through the stream wrapper (hosts without curl),
     * redirects NOT followed.
     *
     * @param resource|null $target
     * @return array{0: int, 1: string|null, 2: string} status, Location, body
     */
    private static function streamHop(string $url, $target): array
    {
        $kontext = stream_context_create([
            'http' => [
                'timeout' => $target === null ? 20 : 120,
                'follow_location' => 0,
                'user_agent' => 'Vereinsbelege-Updater',
                'ignore_errors' => true,
            ],
        ]);
        $quelle = @fopen($url, 'rb', context: $kontext);
        if ($quelle === false) {
            throw new \RuntimeException($target === null ? 'HTTP-Anfrage fehlgeschlagen: ' . $url : 'Download fehlgeschlagen: ' . $url);
        }

        try {
            $kopfzeilen = stream_get_meta_data($quelle)['wrapper_data'] ?? [];
            $status = 0;
            $location = null;
            foreach (is_array($kopfzeilen) ? $kopfzeilen : [] as $zeile) {
                $zeile = (string) $zeile;
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                    $status = (int) $m[1];
                    $location = null;
                } elseif (preg_match('/^Location:\s*(.+)$/i', $zeile, $m) === 1) {
                    $location = trim($m[1]);
                }
            }

            if (self::istWeiterleitung($status) || $status >= 400) {
                return [$status, $location, ''];
            }
            if ($target === null) {
                return [$status, null, (string) stream_get_contents($quelle)];
            }
            stream_copy_to_stream($quelle, $target);

            return [$status, null, ''];
        } finally {
            fclose($quelle);
        }
    }

    /**
     * The self-test's GET: the installation's own start page, redirects
     * (http -> https) followed freely.
     */
    private static function defaultHttpGet(string $url): string
    {
        if (self::useCurl($url)) {
            return self::curlGet($url);
        }

        $body = @file_get_contents($url, false, self::streamContext());
        if ($body === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $url);
        }

        $statusLine = (string) ((http_get_last_response_headers() ?? [])[0] ?? '');
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
        // No curl_close(), see curlHop().

        if ($body === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . ($fehler !== '' ? $fehler : $url));
        }
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
