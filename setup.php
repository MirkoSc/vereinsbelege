<?php

/**
 * setup.php - Bootstrap installer (docs/spec/06-betrieb.md section 1,
 * Nextcloud style).
 *
 * GENERATED FILE - edit bin/setup.template.php and the shared class
 * app/src/Service/Update/ReleaseDownloader.php, then run
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
}

namespace {

use App\Service\Update\ReleaseDownloader;

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
    // Kept for the code integrity check of the admin area
    // (docs/spec/01-sicherheit.md section 8).
    file_put_contents($sharedDir . '/release_checksums.txt', $checksums);

    $target = $releasesDir . '/v' . $release['version'];
    $downloader->extractTo($zipFile, $target);
    unlink($zipFile);

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

    header('Location: /install');
    exit;
} catch (Throwable $e) {
    setup_page('Fehler', '<p class="fehlertext">Einrichtung fehlgeschlagen: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES)
        . '</p><p><a href="setup.php">Zurück zum Umgebungscheck</a></p>');
}

}
