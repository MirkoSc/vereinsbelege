<?php

/**
 * setup.php - Bootstrap installer (docs/spec/06-betrieb.md section 1,
 * Nextcloud style).
 *
 * GENERATED FILE - edit bin/setup.template.php and the shared classes
 * app/src/Service/Update/ReleaseDownloader.php, ReleaseSignatur.php and
 * DenyHtaccess.php, then run
 * `php bin/build_setup.php`. CI verifies freshness.
 *
 * Upload this single file via FTP into the /web docroot and open it in the
 * browser: environment checklist -> download + verify + unpack the newest
 * release -> create the docroot files and shared/ -> redirect to /install.
 * The installer deletes it once it has written the config.
 */

declare(strict_types=1);

namespace App\Service\Update {
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
        self::pruefeUrl($url, $this->erlaubteHosts);

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
        self::pruefeUrl($url, $this->erlaubteHosts);

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

            return self::istWeiterleitung($status) ? $location : null;
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
                    return $location;
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

/**
 * Authenticity of a release (issue #241, N-02; docs/spec/06-betrieb.md
 * section 1): the release workflow signs checksums.txt with Ed25519
 * (libsodium, detached signature) and publishes the signature as the asset
 * checksums.txt.sig. The list names the SHA-256 of the release ZIP and of
 * every file inside it, so one signature covers the download AND the list
 * the code integrity check compares against later.
 *
 * Inlined into setup.php by bin/build_setup.php like ReleaseDownloader:
 * keep it dependency-free (no App\ imports, no autoloader).
 *
 * The public key is fixed here, in the code: an attacker who can only
 * change files in shared/ or answer with a forged release cannot swap it.
 * As long as PUBLIC_KEY is empty (no key pair created yet) a missing or
 * unverifiable signature is a WARNING, not a refusal - releases must keep
 * working before the maintainer has created the key. Once it is set, every
 * later release must carry a valid signature (docs/betrieb.md, section
 * "Release-Signatur").
 *
 * Formats (all base64, standard alphabet, surrounding whitespace ignored):
 * public key 32 bytes, secret key 64 bytes (sodium_crypto_sign_secretkey),
 * signature 64 bytes over the exact bytes of checksums.txt.
 */
final class ReleaseSignatur
{
    /**
     * Ed25519 public key of the release signing key, base64. Empty = no key
     * pair yet: signatures are not enforced. Set by the maintainer with
     * `php tools/release-signatur.php schluessel` (docs/betrieb.md).
     */
    public const string PUBLIC_KEY = '';

    /** Asset name of the signature next to checksums.txt. */
    public const string ASSET = 'checksums.txt.sig';

    /**
     * Checks $inhalt against $signatur.
     *
     * @param string|null $signatur base64 signature, null when the release
     *        carries none
     * @param string $publicKey base64 public key; empty = none fixed yet
     * @return string|null a German warning when nothing could be verified
     *         because no key is fixed yet, null when the signature is valid
     * @throws \RuntimeException when a key is fixed and the signature is
     *         missing, malformed or wrong
     */
    public static function pruefe(string $inhalt, ?string $signatur, string $publicKey = self::PUBLIC_KEY): ?string
    {
        if (trim($publicKey) === '') {
            return $signatur === null
                ? 'Hinweis: Das Release ist nicht signiert; seine Echtheit wurde nicht geprüft '
                    . '(noch kein Signaturschlüssel festgelegt).'
                : 'Hinweis: Das Release ist signiert, aber diese Version kennt noch keinen Signaturschlüssel; '
                    . 'die Echtheit wurde nicht geprüft.';
        }

        $schluessel = self::dekodiere($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('Der festgelegte Signaturschlüssel ist ungültig.');
        }
        if ($signatur === null) {
            throw new \RuntimeException('Das Release ist nicht signiert – Echtheit nicht bestätigt, Abbruch.');
        }
        $roh = self::dekodiere($signatur, SODIUM_CRYPTO_SIGN_BYTES);
        if ($roh === null || !sodium_crypto_sign_verify_detached($roh, $inhalt, $schluessel)) {
            throw new \RuntimeException(
                'Die Signatur des Releases ist ungültig – das Release ist nicht echt oder wurde verändert, Abbruch.',
            );
        }

        return null;
    }

    /**
     * Whether a key is fixed, i.e. whether pruefe() enforces.
     */
    public static function erzwungen(string $publicKey = self::PUBLIC_KEY): bool
    {
        return trim($publicKey) !== '';
    }

    /**
     * Signs $inhalt (release workflow, via tools/release-signatur.php).
     *
     * @param string $secretKey base64 secret key (64 bytes)
     * @return string base64 signature
     */
    public static function signiere(string $inhalt, string $secretKey): string
    {
        $schluessel = self::dekodiere($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('RELEASE_SIGNING_KEY ist kein gültiger Ed25519-Schlüssel (64 Bytes, base64).');
        }
        $signatur = sodium_crypto_sign_detached($inhalt, $schluessel);
        sodium_memzero($schluessel);

        return base64_encode($signatur);
    }

    /**
     * The public key belonging to a secret key - lets the workflow refuse a
     * secret that does not match PUBLIC_KEY before it publishes a release
     * every installation would reject.
     *
     * @return string base64 public key
     */
    public static function publicKeyZu(string $secretKey): string
    {
        $schluessel = self::dekodiere($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('RELEASE_SIGNING_KEY ist kein gültiger Ed25519-Schlüssel (64 Bytes, base64).');
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($schluessel);
        sodium_memzero($schluessel);

        return base64_encode($public);
    }

    /**
     * A fresh key pair (maintainer, once).
     *
     * @return array{public: string, secret: string} both base64
     */
    public static function erzeugeSchluesselpaar(): array
    {
        $paar = sodium_crypto_sign_keypair();
        $ergebnis = [
            'public' => base64_encode(sodium_crypto_sign_publickey($paar)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($paar)),
        ];
        sodium_memzero($paar);

        return $ergebnis;
    }

    private static function dekodiere(string $wert, int $laenge): ?string
    {
        $roh = base64_decode(trim($wert), true);

        return $roh !== false && strlen($roh) === $laenge ? $roh : null;
    }
}

/**
 * The ".htaccess that denies everything" for the directories next to the
 * docroot (issue #101, docs/spec/06-betrieb.md section 1).
 *
 * shared/, releases/ and current/ are unreachable as long as the domain
 * points at web/ - but that is a setting in the hoster's customer menu.
 * Should a domain ever point one level higher, config.php (server key,
 * database password), every blob and the code would be public. The hosting
 * check proved that the target host evaluates .htaccess, so this costs
 * nothing.
 *
 * current/ is a renamed release directory, so the same content also sits at
 * the root of every release (repository root `.htaccess`, part of the
 * release ZIP; DenyHtaccessTest keeps both identical): that way current/ is
 * locked the moment it is switched in. Writing it here as well covers a
 * rollback to a release built before issue #101.
 *
 * Dependency-free on purpose: bin/build_setup.php inlines this class into
 * setup.php, which runs before there is an autoloader.
 */
final class DenyHtaccess
{
    /**
     * Apache 2.4 (mod_authz_core) understands "Require", 2.2 only
     * "Order/Deny". The IfModule pair picks the one the server knows. Should
     * the host's AllowOverride forbid the directive, Apache answers with a
     * 500 - which serves nothing either.
     */
    public const string CONTENT = <<<'HTACCESS'
        # Written by Vereinsbelege - do not remove. Nothing in this directory
        # may ever be served by the web server, even if a domain is pointed
        # one level too high (docs/spec/06-betrieb.md section 1).
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        HTACCESS;

    /** Directories next to the docroot that must never be served. */
    public const array DIRECTORIES = ['shared', 'releases', 'current'];

    public const string PROBLEM_MISSING_DIR = 'Verzeichnis fehlt';
    public const string PROBLEM_NOT_WRITABLE = 'Datei nicht schreibbar – bitte Schreibrechte prüfen';
    public const string PROBLEM_FOREIGN_FILE = 'vorhandene .htaccess sperrt nicht vollständig – bitte von Hand prüfen';

    /**
     * Makes sure $dir carries a denying .htaccess. A file that is already
     * there is never overwritten - it may have been tuned by hand - and
     * counts as fine only when denies() says it locks the whole directory.
     *
     * @return string|null null when the directory is protected, otherwise
     *         one of the PROBLEM_* texts (German, shown to the admin)
     */
    public static function ensure(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return self::PROBLEM_MISSING_DIR;
        }

        $file = rtrim($dir, '/') . '/.htaccess';
        if (file_exists($file)) {
            if (!is_file($file)) {
                return self::PROBLEM_NOT_WRITABLE;
            }

            return self::denies((string) @file_get_contents($file)) ? null : self::PROBLEM_FOREIGN_FILE;
        }

        return @file_put_contents($file, self::CONTENT, LOCK_EX) === strlen(self::CONTENT)
            ? null
            : self::PROBLEM_NOT_WRITABLE;
    }

    /**
     * ensure() for every directory in DIRECTORIES below $baseDir (the parent
     * of web/). A directory that does not exist yet is skipped: there is
     * nothing in it to protect, and whoever creates it calls this again.
     *
     * @return array<string, string> unprotected directory (relative name,
     *         never a server path) => problem, empty when all are locked
     */
    public static function ensureAll(string $baseDir): array
    {
        $problems = [];
        foreach (self::DIRECTORIES as $name) {
            $dir = rtrim($baseDir, '/') . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $problem = self::ensure($dir);
            if ($problem !== null) {
                $problems[$name . '/'] = $problem;
            }
        }

        return $problems;
    }

    /**
     * "shared/ (reason), releases/ (reason)" - for setup.php, the installer
     * and the update log.
     *
     * @param array<string, string> $problems what ensureAll() returned
     */
    public static function describe(array $problems): string
    {
        $parts = [];
        foreach ($problems as $dir => $problem) {
            $parts[] = $dir . ' (' . $problem . ')';
        }

        return implode(', ', $parts);
    }

    /**
     * Whether an existing .htaccess locks the whole directory on its own -
     * deliberately strict, a false "protected" is the expensive mistake:
     *
     *  - a "Require all denied" or "Deny from all" line outside any section
     *    other than <IfModule> (inside <Files>, <FilesMatch>, <If> ... it
     *    only covers some requests);
     *  - and nothing that grants access again (Require all granted, Allow
     *    from, Satisfy any).
     */
    public static function denies(string $content): bool
    {
        $depth = 0;
        $denies = false;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('#^</\s*([A-Za-z]+)#', $line, $m) === 1) {
                if (strcasecmp($m[1], 'IfModule') !== 0) {
                    --$depth;
                }
                continue;
            }
            if (preg_match('#^<\s*([A-Za-z]+)#', $line, $m) === 1) {
                if (strcasecmp($m[1], 'IfModule') !== 0) {
                    ++$depth;
                }
                continue;
            }
            if (preg_match('/^(Require\s+all\s+granted|Allow\s+from|Satisfy\s+any)\b/i', $line) === 1) {
                return false;
            }
            if ($depth === 0 && preg_match('/^(Require\s+all\s+denied|Deny\s+from\s+all)$/i', $line) === 1) {
                $denies = true;
            }
        }

        return $denies;
    }
}
}

namespace {

use App\Service\Update\DenyHtaccess;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSignatur;

error_reporting(E_ALL);
// Arguments out of stack traces before anything else can throw: this file
// carries no passwords, but the target host ships
// zend.exception_ignore_args = 0 (hosting finding M0) and the .user.ini
// written below only takes effect for the NEXT request.
ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');
ini_set('display_errors', '1');

$webDir = __DIR__;
$rootDir = dirname(__DIR__);

/** @return list<array{label: string, ok: bool, detail: string}> */
function setup_checks(string $webDir, string $rootDir): array
{
    $checks = [];
    $checks[] = [
        'label' => 'PHP-Version ≥ 8.5',
        'ok' => version_compare(PHP_VERSION, '8.5.0', '>='),
        'detail' => 'gefunden: ' . PHP_VERSION,
    ];
    $checks[] = [
        'label' => 'ZipArchive verfügbar',
        'ok' => class_exists(\ZipArchive::class),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'PDO MySQL verfügbar',
        'ok' => extension_loaded('pdo_mysql'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'libsodium verfügbar (Verschlüsselung aller Belegdaten)',
        'ok' => extension_loaded('sodium') && function_exists('sodium_crypto_box_seal'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'GD verfügbar (Bildaufbereitung als Notnagel)',
        'ok' => extension_loaded('gd') && function_exists('imagecreatefromjpeg'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'HTTPS-Downloads möglich (curl oder allow_url_fopen)',
        'ok' => function_exists('curl_init')
            || (ini_get('allow_url_fopen') === '1' && extension_loaded('openssl')),
        'detail' => function_exists('curl_init') ? 'curl' : 'Stream-Wrapper',
    ];
    $checks[] = [
        'label' => 'Schreibrechte oberhalb des DocumentRoot',
        'ok' => is_writable($rootDir),
        'detail' => $rootDir,
    ];
    $checks[] = [
        'label' => 'Schreibrechte im DocumentRoot',
        'ok' => is_writable($webDir),
        'detail' => $webDir,
    ];

    $renameOk = false;
    $probe = $rootDir . '/.setup_probe_' . getmypid();
    if (@file_put_contents($probe, 'x') !== false) {
        $renameOk = @rename($probe, $probe . '_renamed');
        @unlink($probe . '_renamed');
        @unlink($probe);
    }
    $checks[] = ['label' => 'rename() funktioniert', 'ok' => (bool) $renameOk, 'detail' => ''];

    return $checks;
}

/**
 * Heuristic against installing into the FTP root: in the intended layout
 * (domain docroot = a web/ SUBFOLDER of an otherwise empty directory) the
 * parent of setup.php contains nothing foreign. Entries besides the docroot
 * itself and our own directories indicate that setup.php probably sits
 * directly in the domain folder and the data would land in the account root.
 *
 * @return list<string>
 */
function setup_fremde_eintraege(string $webDir, string $rootDir): array
{
    $eigene = [basename($webDir), 'current', 'releases', 'shared'];
    $fremd = [];
    foreach (scandir($rootDir) ?: [] as $eintrag) {
        if ($eintrag === '.' || $eintrag === '..' || in_array($eintrag, $eigene, true)) {
            continue;
        }
        $fremd[] = $eintrag;
    }

    return $fremd;
}

function setup_page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        // setup.php ships as its own release asset and cannot link the
        // application stylesheet, so it carries this one inline block. Same
        // custom properties as public/css/app.css.
        . '<style>:root{--farbe-text:#1c1d1f;--farbe-hintergrund:#ffffff;--farbe-rand:#d6d8dc;'
        . '--farbe-link:#1a5c9e;--farbe-ok:#1c6b3f;--farbe-fehler:#a3231b}'
        . '@media (prefers-color-scheme: dark){:root{--farbe-text:#e8e9ea;--farbe-hintergrund:#16171a;'
        . '--farbe-rand:#33363b;--farbe-link:#7fb4ee;--farbe-ok:#6cc48f;--farbe-fehler:#f08a83}}'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;max-width:40rem;'
        . 'margin:0 auto;padding:1rem;line-height:1.5;background:var(--farbe-hintergrund);'
        . 'color:var(--farbe-text)}code{overflow-wrap:anywhere}'
        . 'li.ok::marker{content:"\2714 ";color:var(--farbe-ok)}'
        . 'li.fehler::marker{content:"\2718 ";color:var(--farbe-fehler)}'
        . 'button{padding:.6rem 1.2rem;font:inherit;border:1px solid var(--farbe-rand);'
        . 'border-radius:6px;cursor:pointer}.fehlertext{color:var(--farbe-fehler)}</style>'
        . '</head><body><h1>Vereinsbelege einrichten</h1>' . $body . '</body></html>';
    exit;
}

// already installed and switched: hand over to the app installer
if (is_dir($rootDir . '/current')) {
    header('Location: /install');
    exit;
}

$fremdeEintraege = setup_fremde_eintraege($webDir, $rootDir);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $checks = setup_checks($webDir, $rootDir);
    $allOk = !in_array(false, array_column($checks, 'ok'), true);

    $items = '';
    foreach ($checks as $check) {
        $items .= '<li class="' . ($check['ok'] ? 'ok' : 'fehler') . '">'
            . htmlspecialchars($check['label'], ENT_QUOTES)
            . ($check['detail'] !== '' ? ' <small>(' . htmlspecialchars($check['detail'], ENT_QUOTES) . ')</small>' : '')
            . '</li>';
    }

    $layout = '<h2>Verzeichnisse</h2><p>So wird installiert:</p><ul>'
        . '<li><strong>DocumentRoot</strong> (dieses Verzeichnis): <code>'
        . htmlspecialchars($webDir, ENT_QUOTES) . '</code>'
        . ' – hier liegen danach nur der kleine index.php-Verweis, .htaccess und .user.ini.</li>'
        . '<li><strong>Datenverzeichnis</strong> (eine Ebene darüber, nicht per Browser erreichbar): <code>'
        . htmlspecialchars($rootDir, ENT_QUOTES) . '</code>'
        . ' – hier entstehen <code>current/</code>, <code>releases/</code> und <code>shared/</code>'
        . ' (Konfiguration, Belegdateien, Backups).</li></ul>';

    $warnung = '';
    $bestaetigung = '';
    if ($fremdeEintraege !== []) {
        $liste = htmlspecialchars(implode(', ', array_slice($fremdeEintraege, 0, 8)), ENT_QUOTES)
            . (count($fremdeEintraege) > 8 ? ', …' : '');
        $warnung = '<p class="fehlertext"><strong>Achtung:</strong> Das Datenverzeichnis ist nicht leer'
            . ' (' . $liste . '). Vermutlich liegt setup.php direkt im Domain-Ordner und die Daten'
            . ' würden im FTP-Hauptverzeichnis landen. Empfohlen: im Domain-Ordner einen Unterordner'
            . ' <code>web</code> anlegen, die (Sub-)Domain im Kundenmenü auf diesen <code>web</code>-Ordner'
            . ' zeigen lassen, setup.php dorthin verschieben und neu aufrufen.</p>';
        $bestaetigung = '<p><label><input type="checkbox" name="layout_bestaetigt" value="1"> '
            . 'Ich möchte trotzdem hier installieren – die Datenverzeichnisse sollen in '
            . '<code>' . htmlspecialchars($rootDir, ENT_QUOTES) . '</code> angelegt werden.</label></p>';
    }

    $form = $allOk
        ? '<form method="post"><p><label>Release-Kanal: <select name="kanal">'
            . '<option value="stable">stable (empfohlen)</option>'
            . '<option value="beta">beta (Vorabversionen, Testinstanz)</option>'
            . '</select></label></p>' . $bestaetigung . '<button type="submit">Installation starten</button></form>'
        : '<p class="fehlertext">Bitte zuerst die markierten Punkte beheben und die Seite neu laden.</p>';

    setup_page('Umgebungscheck', '<h2>Umgebungscheck</h2><ul>' . $items . '</ul>' . $layout . $warnung . $form);
}

// POST guard: with a suspicious layout the confirmation checkbox is required
if ($fremdeEintraege !== [] && ($_POST['layout_bestaetigt'] ?? '') !== '1') {
    setup_page('Bitte Struktur prüfen', '<p class="fehlertext">Installation nicht gestartet: Das Datenverzeichnis <code>'
        . htmlspecialchars($rootDir, ENT_QUOTES) . '</code> ist nicht leer. Bitte die empfohlene Struktur mit'
        . ' <code>web</code>-Unterordner einrichten – oder die Bestätigung auf der vorherigen Seite ankreuzen.</p>'
        . '<p><a href="setup.php">Zurück zum Umgebungscheck</a></p>');
}

// POST: download, verify, unpack, create the layout, switch, redirect
try {
    $kanal = ($_POST['kanal'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $downloader = new ReleaseDownloader();

    $release = $downloader->findLatestRelease($kanal);
    if ($release === null) {
        throw new RuntimeException('Kein Release auf GitHub gefunden (Kanal ' . $kanal . ').');
    }

    $releasesDir = $rootDir . '/releases';
    $sharedDir = $rootDir . '/shared';
    foreach ([
        $releasesDir,
        $sharedDir,
        $sharedDir . '/var',
        $sharedDir . '/var/log',
        $sharedDir . '/var/tmp',
        $sharedDir . '/var/blobs',
        $sharedDir . '/var/backups',
    ] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    // Deny-all .htaccess in shared/ and releases/ before anything lands in
    // them (issue #101): they are only private as long as the domain points
    // at web/. current/ brings its own from the release ZIP. A failure does
    // not stop the installation - it is shown below and again on /install,
    // which retries.
    $ungeschuetzt = DenyHtaccess::ensureAll($rootDir);

    // The channel the admin just picked, handed to the installer: there is
    // no database yet to hold the setting, and without this an instance
    // installed from a pre-release would afterwards look for updates on
    // stable. The installer reads the file and deletes it.
    file_put_contents($sharedDir . '/setup_kanal.txt', $kanal);

    // The local name must stay the asset name - the checksum is matched by
    // filename against checksums.txt.
    $zipFile = $releasesDir . '/' . ReleaseDownloader::zipFilename($release['zip_url']);
    $downloader->downloadTo($release['zip_url'], $zipFile);
    $checksums = $downloader->fetchText($release['checksums_url']);
    $downloader->verifyChecksum($zipFile, $checksums);
    // Authenticity (issue #241): refused once a public key is fixed in
    // ReleaseSignatur, a warning on the next page before that.
    $signatur = $release['signatur_url'] === null ? null : $downloader->fetchText($release['signatur_url']);
    $signaturHinweis = ReleaseSignatur::pruefe($checksums, $signatur);

    $target = $releasesDir . '/v' . $release['version'];
    $downloader->extractTo($zipFile, $target);
    unlink($zipFile);
    // The list (and its signature) travel with the release directory: the
    // code integrity check of the admin area compares current/ against it
    // (docs/spec/01-sicherheit.md section 8).
    ReleaseDownloader::speichereListe($target, $checksums, $signatur);

    // The three docroot files (docs/spec/06-betrieb.md section 1). They are
    // taken from the release that was just unpacked, where they live under
    // docker/web/ - copying instead of duplicating them here is what keeps
    // the development environment and a fresh installation from drifting
    // apart. The shim is asserted against ReleaseSwitcher::SHIM by
    // ShimContentTest.
    foreach (['index.php', '.htaccess', '.user.ini'] as $datei) {
        $quelle = $target . '/docker/web/' . $datei;
        $ziel = $webDir . '/' . $datei;
        if (!is_file($quelle)) {
            throw new RuntimeException('Release unvollständig, Datei fehlt: docker/web/' . $datei);
        }
        // An existing .htaccess is never overwritten: it may be hand-tuned,
        // and the docroot is the one place no release ZIP ever touches. The
        // shim is the exception - it MUST match the release.
        if ($datei !== 'index.php' && is_file($ziel)) {
            continue;
        }
        if (copy($quelle, $ziel) === false) {
            throw new RuntimeException('Datei kann nicht in den DocumentRoot geschrieben werden: ' . $datei);
        }
    }

    if (!rename($target, $rootDir . '/current')) {
        throw new RuntimeException('Release kann nicht nach current/ verschoben werden.');
    }

    $hinweise = '';
    if ($ungeschuetzt !== []) {
        $hinweise .= '<p class="fehlertext"><strong>Achtung:</strong> Keine'
            . ' .htaccess-Sperre in ' . htmlspecialchars(DenyHtaccess::describe($ungeschuetzt), ENT_QUOTES)
            . '. Solange die Domain auf den <code>web</code>-Ordner zeigt, ist das unkritisch – zeigt sie'
            . ' eine Ebene höher, wären Konfiguration und Belegdateien abrufbar.'
            . ' Die Installation versucht es erneut.</p>';
    }
    if ($signaturHinweis !== null) {
        $hinweise .= '<p>' . htmlspecialchars($signaturHinweis, ENT_QUOTES)
            . ' Die Prüfsumme des Downloads wurde bestätigt.</p>';
    }
    if ($hinweise !== '') {
        setup_page('Hinweis', $hinweise . '<p><a href="/install">Weiter zur Installation</a></p>');
    }

    header('Location: /install');
    exit;
} catch (Throwable $e) {
    setup_page('Fehler', '<p class="fehlertext">Einrichtung fehlgeschlagen: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES)
        . '</p><p><a href="setup.php">Zurück zum Umgebungscheck</a></p>');
}

}
