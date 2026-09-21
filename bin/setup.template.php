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
//__SHARED_CODE__
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
