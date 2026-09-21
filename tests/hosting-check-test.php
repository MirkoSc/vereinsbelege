<?php

declare(strict_types=1);

/**
 * Tests for tools/hosting-check.php.
 *
 * The hosting check has to stay dependency-free (see docs/spec/06-betrieb.md
 * §5), and at milestone M0 the project has no Composer, no PHPUnit and no
 * vendor/ yet – those arrive with M1-1, where this file becomes a regular
 * PHPUnit TestCase. Until then the assertions run on plain PHP:
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tests/hosting-check-test.php
 *
 * Warnings, notices and deprecations fail the run, just like the future
 * PHPUnit configuration (failOnWarning, failOnDeprecation).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    if ((error_reporting() & $errno) === 0) {
        return false; // suppressed with @, which the probes use on purpose
    }

    throw new RuntimeException(sprintf('PHP-Diagnose [%d] %s in %s:%d', $errno, $message, $file, $line));
});

define('HOSTING_CHECK_LOAD_ONLY', true);
require __DIR__ . '/../tools/hosting-check.php';

// ---------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------

final class HcTestRunner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $current = '';

    public function run(string $name, callable $test): void
    {
        $this->current = $name;
        try {
            $test($this);
            $this->passed++;
        } catch (Throwable $e) {
            $this->failures[] = $name . ': ' . $e->getMessage();
        }
    }

    public function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                '%serwartet %s, erhalten %s',
                $message === '' ? '' : $message . ' – ',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    public function true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($message === '' ? '' : $message . ' – ') . 'fehlt: ' . $needle);
        }
    }

    public function notContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException(($message === '' ? '' : $message . ' – ') . 'darf nicht enthalten sein: ' . $needle);
        }
    }

    public function report(): int
    {
        echo 'Hosting-Check-Tests: ' . $this->passed . ' bestanden, '
            . count($this->failures) . ' fehlgeschlagen' . PHP_EOL;
        foreach ($this->failures as $failure) {
            echo '  FEHLER ' . $failure . PHP_EOL;
        }

        return $this->failures === [] ? 0 : 1;
    }
}

$t = new HcTestRunner();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$t->run('hc_parse_bytes versteht ini-Kurzschreibweisen', static function (HcTestRunner $t): void {
    $t->same(134217728, hc_parse_bytes('128M'));
    $t->same(1073741824, hc_parse_bytes('1G'));
    $t->same(1048576, hc_parse_bytes('1024K'));
    $t->same(512, hc_parse_bytes('512'));
    $t->same(67108864, hc_parse_bytes(' 64m '));
    $t->same(2097152, hc_parse_bytes('2MB'));
    $t->same(0, hc_parse_bytes('0'));
});

$t->run('hc_parse_bytes meldet unbegrenzt und Unlesbares', static function (HcTestRunner $t): void {
    $t->same(-1, hc_parse_bytes('-1'), 'unbegrenzt');
    $t->same(null, hc_parse_bytes(''), 'leer');
    $t->same(null, hc_parse_bytes('unbekannt'), 'Text');
    $t->same(null, hc_parse_bytes('12,5M'), 'Komma');
});

$t->run('hc_format_bytes formatiert lesbar', static function (HcTestRunner $t): void {
    $t->same('unbekannt', hc_format_bytes(null));
    $t->same('unbegrenzt', hc_format_bytes(-1));
    $t->same('0 B', hc_format_bytes(0));
    $t->same('1 KiB', hc_format_bytes(1024));
    $t->same('1,5 KiB', hc_format_bytes(1536));
    $t->same('256 MiB', hc_format_bytes(268435456));
});

$t->run('hc_token_matches ist streng', static function (HcTestRunner $t): void {
    $t->true(hc_token_matches('s3cret', 's3cret'), 'gleiches Token muss passen');
    $t->true(!hc_token_matches('s3cret', 'S3CRET'), 'Groß-/Kleinschreibung zählt');
    $t->true(!hc_token_matches('s3cret', 'anderes'), 'falsches Token');
    $t->true(!hc_token_matches('', ''), 'ohne konfiguriertes Token nie Zugriff');
    $t->true(!hc_token_matches('', 'irgendwas'), 'leere Erwartung nie erfüllbar');
    $t->true(!hc_token_matches('s3cret', ''), 'leere Eingabe nie erfüllbar');
});

$t->run('hc_status_rank ordnet nach Schwere', static function (HcTestRunner $t): void {
    $t->true(
        hc_status_rank(HC_STATUS_FAIL) > hc_status_rank(HC_STATUS_WARN)
        && hc_status_rank(HC_STATUS_WARN) > hc_status_rank(HC_STATUS_OK)
        && hc_status_rank(HC_STATUS_OK) > hc_status_rank(HC_STATUS_SKIP)
        && hc_status_rank(HC_STATUS_SKIP) > hc_status_rank(HC_STATUS_INFO),
        'Reihenfolge fail > warn > ok > skip > info',
    );
});

$t->run('hc_rate_min bewertet Mindestwerte', static function (HcTestRunner $t): void {
    $t->same(HC_STATUS_OK, hc_rate_min(300, 256));
    $t->same(HC_STATUS_OK, hc_rate_min(256, 256), 'Grenzwert gilt als erfüllt');
    $t->same(HC_STATUS_WARN, hc_rate_min(200, 256, 128));
    $t->same(HC_STATUS_FAIL, hc_rate_min(64, 256, 128));
    $t->same(HC_STATUS_FAIL, hc_rate_min(64, 256), 'ohne Warnschwelle direkt fail');
    $t->same(HC_STATUS_OK, hc_rate_min(-1, 256), 'unbegrenzt ist in Ordnung');
    $t->same(HC_STATUS_WARN, hc_rate_min(-1, 256, null, false), 'unbegrenzt kann auch warnen');
    $t->same(HC_STATUS_WARN, hc_rate_min(null, 256), 'unbekannt warnt');
});

$t->run('hc_row liefert die feste Zeilenform', static function (HcTestRunner $t): void {
    $row = hc_row('id', 'Label', 'Wert', 'Annahme', HC_STATUS_OK);
    $t->same(['id', 'label', 'value', 'expected', 'status', 'detail'], array_keys($row));
    $t->same('', $row['detail'], 'Detail ist optional');
});

$t->run('hc_summarize zählt und findet das schlechteste Ergebnis', static function (HcTestRunner $t): void {
    $groups = [
        ['id' => 'a', 'label' => 'A', 'rows' => [
            hc_row('1', 'a', 'v', 'e', HC_STATUS_OK),
            hc_row('2', 'b', 'v', 'e', HC_STATUS_OK),
            hc_row('3', 'c', 'v', 'e', HC_STATUS_WARN),
        ]],
        ['id' => 'b', 'label' => 'B', 'rows' => [
            hc_row('4', 'd', 'v', 'e', HC_STATUS_INFO),
            hc_row('5', 'e', 'v', 'e', HC_STATUS_SKIP),
        ]],
    ];
    $summary = hc_summarize($groups);
    $t->same(2, $summary[HC_STATUS_OK]);
    $t->same(1, $summary[HC_STATUS_WARN]);
    $t->same(0, $summary[HC_STATUS_FAIL]);
    $t->same(1, $summary[HC_STATUS_INFO]);
    $t->same(1, $summary[HC_STATUS_SKIP]);
    $t->same(HC_STATUS_WARN, $summary['worst']);

    $groups[1]['rows'][] = hc_row('6', 'f', 'v', 'e', HC_STATUS_FAIL);
    $t->same(HC_STATUS_FAIL, hc_summarize($groups)['worst'], 'ein fail schlägt alles');
});

$t->run('hc_redact entfernt Geheimnisse aus der Ausgabe', static function (HcTestRunner $t): void {
    $redacted = hc_redact([
        'smtp_pass' => 'geheim',
        'db_pass' => 'geheim',
        'token' => 's3cret',
        'api_key' => 'sk-123',
        'secret' => 'x',
        'smtp_user' => 'm0000000',
        'db_user' => 'd0000000',
        'login' => 'abc',
        'smtp_host' => 'mail.example.org',
        'leer' => '',
    ]);
    $t->same('***', $redacted['smtp_pass']);
    $t->same('***', $redacted['db_pass']);
    $t->same('***', $redacted['token']);
    $t->same('***', $redacted['api_key']);
    $t->same('***', $redacted['secret']);
    $t->same('***', $redacted['smtp_user'], 'Benutzernamen gehören nicht in einen Befundbericht');
    $t->same('***', $redacted['db_user']);
    $t->same('***', $redacted['login']);
    $t->same('mail.example.org', $redacted['smtp_host'], 'harmlose Werte bleiben');
    $t->same('', $redacted['leer'], 'leere Werte bleiben leer');
    $t->notContains('geheim', json_encode($redacted, JSON_THROW_ON_ERROR));
    $t->notContains('m0000000', json_encode($redacted, JSON_THROW_ON_ERROR));
});

$t->run('hc_pad füllt auf und kürzt mit Auslassung', static function (HcTestRunner $t): void {
    $t->same('ab   ', hc_pad('ab', 5));
    $t->same('abcde', hc_pad('abcde', 5));
    $t->same('abcd…', hc_pad('abcdefgh', 5));
    $t->same(5, mb_strlen(hc_pad('äöüßabc', 5)), 'Umlaute zählen als ein Zeichen');
});

$t->run('hc_status_symbol markiert jeden Status', static function (HcTestRunner $t): void {
    $t->same('[ ok ]', hc_status_symbol(HC_STATUS_OK));
    $t->same('[warn]', hc_status_symbol(HC_STATUS_WARN));
    $t->same('[FAIL]', hc_status_symbol(HC_STATUS_FAIL));
    $t->same('[skip]', hc_status_symbol(HC_STATUS_SKIP));
    $t->same('[info]', hc_status_symbol(HC_STATUS_INFO));
});

// ---------------------------------------------------------------------------
// Parameters and dispatch
// ---------------------------------------------------------------------------

$t->run('hc_params_from_argv liest Optionen', static function (HcTestRunner $t): void {
    $params = hc_params_from_argv([
        'tools/hosting-check.php',
        '--format=json',
        '--outbound=0',
        '--test',
        'positional',
        '--url=https://example.org/delay/60?x=1',
    ]);
    $t->same('json', $params['format']);
    $t->same('0', $params['outbound']);
    $t->same('1', $params['test'], 'Schalter ohne Wert bedeutet 1');
    $t->same('https://example.org/delay/60?x=1', $params['url'], 'Werte mit = bleiben ganz');
    $t->true(!isset($params['positional']), 'freie Argumente werden ignoriert');
});

$t->run('hc_params_from_request lässt POST gewinnen', static function (HcTestRunner $t): void {
    $params = hc_params_from_request(
        ['token' => 'aus-get', 'format' => 'json'],
        ['token' => 'aus-post', 'smtp_pass' => 'geheim'],
    );
    $t->same('aus-post', $params['token']);
    $t->same('json', $params['format']);
    $t->same('geheim', $params['smtp_pass']);
});

$t->run('hc_params_from_request ignoriert Nicht-Strings', static function (HcTestRunner $t): void {
    $params = hc_params_from_request(['a' => ['array'], 'b' => 'ok'], [2 => 'numerischer Schlüssel']);
    $t->same(['b' => 'ok'], $params);
});

$t->run('hc_presented_token nimmt Parameter oder Kopfzeile', static function (HcTestRunner $t): void {
    $t->same('aus-param', hc_presented_token(['token' => 'aus-param'], ['HTTP_X_HOSTING_CHECK_TOKEN' => 'aus-header']));
    $t->same('aus-header', hc_presented_token([], ['HTTP_X_HOSTING_CHECK_TOKEN' => 'aus-header']));
    $t->same('', hc_presented_token([], []));
    $t->same('', hc_presented_token(['token' => ''], []));
});

$t->run('hc_format kennt Standard und Rückfall', static function (HcTestRunner $t): void {
    $t->same('json', hc_format(['format' => 'JSON'], false), 'Schreibweise egal');
    $t->same('text', hc_format(['format' => 'text'], false));
    $t->same('html', hc_format([], false), 'Web liefert HTML');
    $t->same('text', hc_format([], true), 'CLI liefert Text');
    $t->same('html', hc_format(['format' => 'xml'], false), 'unbekanntes Format fällt zurück');
});

$t->run('hc_mode akzeptiert nur bekannte Tests', static function (HcTestRunner $t): void {
    $t->same('report', hc_mode([]));
    $t->same('longrun', hc_mode(['test' => 'longrun']));
    $t->same('stream', hc_mode(['test' => 'STREAM']));
    $t->same('smtp', hc_mode(['test' => 'smtp']));
    $t->same('report', hc_mode(['test' => 'rm -rf']), 'Unbekanntes fällt auf report zurück');
});

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

/** One offline report, reused by the following tests. */
$report = hc_build_report(['outbound' => '0']);

$t->run('hc_build_report liefert alle Gruppen', static function (HcTestRunner $t) use ($report): void {
    $ids = array_column($report['groups'], 'id');
    $t->same(
        ['php', 'extensions', 'crypto', 'database', 'outbound', 'filesystem', 'htaccess', 'notes'],
        $ids,
    );
    $t->same('vereinsbelege-hosting-check', $report['tool']);
    $t->same('report', $report['mode']);
    $t->same(HC_VERSION, $report['version']);
    $t->true($report['generated_at'] !== '', 'Zeitstempel fehlt');
});

$t->run('jede Zeile hat die feste Form und einen erlaubten Status', static function (HcTestRunner $t) use ($report): void {
    $allowed = [HC_STATUS_OK, HC_STATUS_WARN, HC_STATUS_FAIL, HC_STATUS_INFO, HC_STATUS_SKIP];
    $seen = [];
    foreach ($report['groups'] as $group) {
        $t->true($group['rows'] !== [], 'Gruppe ' . $group['id'] . ' ist leer');
        foreach ($group['rows'] as $row) {
            $t->same(['id', 'label', 'value', 'expected', 'status', 'detail'], array_keys($row), 'Zeile ' . $row['id']);
            $t->true(in_array($row['status'], $allowed, true), 'Status unbekannt: ' . $row['status']);
            $t->true($row['label'] !== '' && $row['value'] !== '', 'Zeile ' . $row['id'] . ' ohne Inhalt');
            $t->true(!isset($seen[$row['id']]), 'doppelte Zeilen-ID: ' . $row['id']);
            $seen[$row['id']] = true;
        }
    }
});

$t->run('der Bericht deckt die Prüfpunkte der Spec ab', static function (HcTestRunner $t) use ($report): void {
    $ids = [];
    foreach ($report['groups'] as $group) {
        $ids = array_merge($ids, array_column($group['rows'], 'id'));
    }
    $required = [
        'php_version', 'memory_limit', 'max_execution_time', 'upload_max_filesize',
        'post_max_size', 'max_input_time', 'disable_functions',
        'ext_sodium', 'ext_gd', 'ext_imagick', 'ext_zip', 'ext_curl', 'ext_openssl',
        'ext_mbstring', 'ext_intl', 'ext_fileinfo', 'ext_pdo_mysql', 'ext_iconv',
        'gd_formats', 'pwhash_interactive', 'pwhash_moderate', 'password_argon2id',
        'fs_rename_dir', 'fs_writable', 'fs_above_document_root',
        'cron_interval', 'longrun_limit', 'stream_limit', 'smtp', 'shared_reachable',
        'htaccess_deny',
    ];
    foreach ($required as $id) {
        $t->true(in_array($id, $ids, true), 'Prüfpunkt fehlt im Bericht: ' . $id);
    }
});

$t->run('Datenbank und ausgehende Verbindungen werden ohne Angaben übersprungen', static function (HcTestRunner $t) use ($report): void {
    $byId = [];
    foreach ($report['groups'] as $group) {
        foreach ($group['rows'] as $row) {
            $byId[$row['id']] = $row;
        }
    }
    $t->same(HC_STATUS_SKIP, $byId['db_connect']['status'], 'ohne Zugangsdaten kein DB-Test');
    $t->same(HC_STATUS_SKIP, $byId['outbound']['status'], 'outbound=0 überspringt die Netzprüfung');
});

$t->run('hc_public_url_for leitet die öffentliche Adresse aus dem Request ab', static function (HcTestRunner $t): void {
    $t->same(
        'https://finanzen.example.de/tools/hc_probe_x/probe.txt',
        hc_public_url_for('hc_probe_x/probe.txt', [
            'HTTP_HOST' => 'finanzen.example.de',
            'SCRIPT_NAME' => '/tools/hosting-check.php',
            'HTTPS' => 'on',
        ]),
    );
    $t->same(
        'http://example.de:8080/hosting-check/probe.txt',
        hc_public_url_for('/hosting-check/probe.txt', [
            'HTTP_HOST' => 'example.de:8080',
            'SCRIPT_NAME' => '/hosting-check.php',
        ]),
        'ohne HTTPS und im DocumentRoot',
    );
    $t->same(
        'http://example.de/p/probe.txt',
        hc_public_url_for('p/probe.txt', [
            'HTTP_HOST' => 'example.de',
            'SCRIPT_NAME' => '/hosting-check.php',
            'HTTPS' => 'off',
        ]),
        'HTTPS=off zählt als unverschlüsselt',
    );
});

$t->run('hc_public_url_for verweigert unbrauchbare Request-Daten', static function (HcTestRunner $t): void {
    $t->same(null, hc_public_url_for('p', []), 'ohne Host');
    $t->same(null, hc_public_url_for('p', ['HTTP_HOST' => 'example.de']), 'ohne SCRIPT_NAME');
    $t->same(
        null,
        hc_public_url_for('p', ['HTTP_HOST' => 'evil.example.de/../x', 'SCRIPT_NAME' => '/a.php']),
        'Hostname mit Sonderzeichen wird nicht verwendet',
    );
    $t->same(
        null,
        hc_public_url_for('p', ['HTTP_HOST' => 'a b', 'SCRIPT_NAME' => '/a.php']),
        'Leerzeichen im Host',
    );
    $t->same(
        null,
        hc_public_url_for('p', ['HTTP_HOST' => 'example.de', 'SCRIPT_NAME' => 'relativ.php']),
        'SCRIPT_NAME muss absolut sein',
    );
});

$t->run('hc_htaccess_deny sperrt für alte und neue Apache-Versionen', static function (HcTestRunner $t): void {
    $snippet = hc_htaccess_deny();
    $t->contains('Require all denied', $snippet);
    $t->contains('Deny from all', $snippet);
    $t->contains('mod_authz_core.c', $snippet);
    $t->true(str_ends_with($snippet, "
"), 'Datei soll mit einem Zeilenumbruch enden');
});

$t->run('hc_group_htaccess prüft nichts ohne echten Webserver', static function (HcTestRunner $t): void {
    $group = hc_group_htaccess([]);
    $t->same('htaccess', $group['id']);
    $t->same(1, count($group['rows']));
    $t->same(HC_STATUS_SKIP, $group['rows'][0]['status'], 'auf der Kommandozeile gibt es keinen Selbstaufruf');
    $rest = array_values(array_diff(scandir(__DIR__ . '/../tools') ?: [], ['.', '..']));
    $t->same(['hosting-check.php'], $rest, 'im Skript-Verzeichnis darf nichts zurückbleiben');
});

$t->run('hc_outbound_targets prüft die Anbieter der Spec', static function (HcTestRunner $t): void {
    $targets = hc_outbound_targets([]);
    $t->same(['openai', 'anthropic', 'github'], array_keys($targets));
    $t->contains('api.openai.com', $targets['openai']['url']);
    $t->contains('api.anthropic.com', $targets['anthropic']['url']);
    foreach ($targets as $key => $target) {
        $t->true(str_starts_with($target['url'], 'https://'), $key . ' muss über HTTPS laufen');
        $t->notContains('key', $target['url'], $key . ' darf keinen Schlüssel in der URL tragen');
    }
});

$t->run('hc_outbound_targets nimmt einen eigenen KI-Server auf', static function (HcTestRunner $t): void {
    $targets = hc_outbound_targets(['llm_url' => 'https://pi.example.org/v1/models']);
    $t->same('https://pi.example.org/v1/models', $targets['own']['url']);
    $t->same(['openai', 'anthropic', 'github', 'own'], array_keys($targets));
});

$t->run('hc_http_probe scheitert ohne Ausnahme bei unbrauchbarer URL', static function (HcTestRunner $t): void {
    $result = hc_http_probe('https://invalid.invalid./nichts', 2);
    $t->same(false, $result['ok'], 'nicht auflösbarer Host muss als nicht erreichbar gelten');
    $t->same(0, $result['status']);
    $t->true($result['detail'] !== '', 'Begründung fehlt');
});

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

$t->run('hc_render_json liefert gültiges, lesbares JSON', static function (HcTestRunner $t) use ($report): void {
    $json = hc_render_json($report);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $t->same($report['summary']['worst'], $decoded['summary']['worst']);
    $t->same(count($report['groups']), count($decoded['groups']));
    $t->contains("\n", $json, 'JSON soll eingerückt sein');
    $t->contains('Verschlüsselung', $json, 'Umlaute unverändert (JSON_UNESCAPED_UNICODE)');
});

$t->run('hc_render_text zeigt Status, Befund und Annahme', static function (HcTestRunner $t): void {
    $groups = [['id' => 'php', 'label' => 'PHP-Laufzeit', 'rows' => [
        hc_row('php_version', 'PHP-Version', '8.5.0', '8.5', HC_STATUS_OK, 'Hinweis zur Version'),
        hc_row('ext_gd', 'ext-gd', 'FEHLT', 'vorhanden', HC_STATUS_FAIL),
    ]]];
    $text = hc_render_text([
        'tool' => 'vereinsbelege-hosting-check',
        'version' => HC_VERSION,
        'generated_at' => '2026-01-01T00:00:00+01:00',
        'mode' => 'report',
        'host' => 'test.example.org',
        'summary' => hc_summarize($groups),
        'groups' => $groups,
    ]);
    $t->contains('test.example.org', $text);
    $t->contains('PHP-Laufzeit', $text);
    $t->contains('[FAIL]', $text);
    $t->contains('[ ok ]', $text);
    $t->contains('Annahme: vorhanden', $text);
    $t->contains('Hinweis zur Version', $text);
    $t->contains('Ergebnis: FAIL', $text);
});

$t->run('hc_render_html maskiert Sonderzeichen und bringt kein Skript mit', static function (HcTestRunner $t): void {
    $groups = [['id' => 'php', 'label' => 'PHP & mehr', 'rows' => [
        hc_row('x', '<script>alert(1)</script>', 'a"b', "c'd", HC_STATUS_WARN, '<img src=x onerror=alert(1)>'),
    ]]];
    $html = hc_render_html([
        'tool' => 'vereinsbelege-hosting-check',
        'version' => HC_VERSION,
        'generated_at' => '2026-01-01T00:00:00+01:00',
        'mode' => 'report',
        'host' => '<b>host</b>',
        'summary' => hc_summarize($groups),
        'groups' => $groups,
    ]);
    $t->notContains('<script', $html, 'kein Skript, auch nicht eingeschmuggelt');
    $t->notContains('<img', $html, 'kein eingeschmuggeltes Element');
    $t->notContains('<b>host</b>', $html, 'Hostname muss maskiert sein');
    $t->contains('&lt;script&gt;', $html);
    $t->contains('&lt;img src=x onerror=alert(1)&gt;', $html, 'Angriffsversuch erscheint nur als Text');
    $t->contains('PHP &amp; mehr', $html);
    $t->contains('name="viewport"', $html, 'mobile Ansicht braucht das Viewport-Meta');
    $t->contains('max-width:560px', $html, 'Kartenansicht für schmale Bildschirme');
    $t->contains('noindex', $html, 'Seite darf nicht indexiert werden');
    $t->contains('<textarea', $html, 'JSON zum Kopieren fehlt');
});

// ---------------------------------------------------------------------------
// Probes with side effects
// ---------------------------------------------------------------------------

$t->run('hc_group_filesystem räumt seine Prüfdateien restlos auf', static function (HcTestRunner $t): void {
    $sandbox = sys_get_temp_dir() . '/hc_test_' . bin2hex(random_bytes(6));
    mkdir($sandbox, 0775, true);
    try {
        $group = hc_group_filesystem(['fsdir' => $sandbox]);
        $byId = array_column($group['rows'], 'status', 'id');
        $t->same(HC_STATUS_OK, $byId['fs_writable']);
        $t->same(HC_STATUS_OK, $byId['fs_mkdir']);
        $t->same(HC_STATUS_OK, $byId['fs_rename_dir'], 'rename() von Verzeichnissen muss geprüft werden');
        $t->same(HC_STATUS_OK, $byId['fs_rename_back']);
        $rest = array_values(array_diff(scandir($sandbox) ?: [], ['.', '..']));
        $t->same([], $rest, 'es darf nichts zurückbleiben');
    } finally {
        hc_remove_tree($sandbox);
    }
});

$t->run('hc_group_filesystem unterscheidet DocumentRoot, darin und darüber', static function (HcTestRunner $t): void {
    $root = sys_get_temp_dir() . '/hc_root_' . bin2hex(random_bytes(6));
    mkdir($root . '/public/tools', 0775, true);
    $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = $root . '/public';
    try {
        $status = static fn (string $dir): array => array_column(
            hc_group_filesystem(['fsdir' => $dir])['rows'],
            'status',
            'id',
        );

        $inRoot = $status($root . '/public');
        $t->same(HC_STATUS_WARN, $inRoot['fs_above_document_root'], 'der DocumentRoot selbst liegt nicht über sich');

        $below = $status($root . '/public/tools');
        $t->same(HC_STATUS_WARN, $below['fs_above_document_root'], 'ein Unterverzeichnis ist öffentlich');

        $above = $status($root);
        $t->same(HC_STATUS_OK, $above['fs_above_document_root'], 'nur oberhalb ist shared/ nicht öffentlich');

        // Mit angehängtem Trennzeichen, wie es all-inkl im DocumentRoot liefert.
        $_SERVER['DOCUMENT_ROOT'] = $root . '/public/';
        $t->same(HC_STATUS_WARN, $status($root . '/public')['fs_above_document_root'], 'Schrägstrich am Ende darf nichts ändern');
        $t->same(HC_STATUS_OK, $status($root)['fs_above_document_root']);
    } finally {
        if ($previous === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $previous;
        }
        hc_remove_tree($root);
    }
});

$t->run('hc_group_filesystem meldet fehlendes Schreibrecht', static function (HcTestRunner $t): void {
    $missing = sys_get_temp_dir() . '/hc_missing_' . bin2hex(random_bytes(6));
    $group = hc_group_filesystem(['fsdir' => $missing]);
    $byId = array_column($group['rows'], 'status', 'id');
    $t->same(HC_STATUS_FAIL, $byId['fs_writable']);
    $t->same(HC_STATUS_FAIL, $byId['fs_mkdir'], 'ohne Elternverzeichnis kein mkdir');
    $t->true(!is_dir($missing), 'es darf kein Verzeichnis angelegt bleiben');
});

$t->run('hc_remove_tree löscht verschachtelte Verzeichnisse', static function (HcTestRunner $t): void {
    $root = sys_get_temp_dir() . '/hc_tree_' . bin2hex(random_bytes(6));
    mkdir($root . '/a/b', 0775, true);
    file_put_contents($root . '/a/b/c.txt', 'x');
    file_put_contents($root . '/a/d.txt', 'y');
    hc_remove_tree($root);
    $t->true(!is_dir($root), 'Verzeichnisbaum nicht entfernt');
});

$t->run('hc_group_crypto belegt das Tresor-Modell', static function (HcTestRunner $t): void {
    if (!extension_loaded('sodium')) {
        $group = hc_group_crypto();
        $t->same(HC_STATUS_FAIL, $group['rows'][0]['status'], 'ohne sodium muss der Check scheitern');

        return;
    }
    $byId = array_column(hc_group_crypto()['rows'], 'status', 'id');
    $t->same(HC_STATUS_OK, $byId['sealed_box'], 'Sealed Box ist die Grundlage der öffentlichen Einreichung');
    $t->same(HC_STATUS_OK, $byId['secretstream'], 'secretstream verschlüsselt die Blobs');
    $t->same(HC_STATUS_OK, $byId['random_bytes']);
    $t->true(in_array($byId['pwhash_interactive'], [HC_STATUS_OK, HC_STATUS_WARN, HC_STATUS_SKIP], true), 'Argon2id-Messung fehlt');
});

$t->run('hc_group_database überspringt ohne Zugangsdaten', static function (HcTestRunner $t): void {
    foreach ([[], ['db_host' => 'localhost'], ['db_host' => 'localhost', 'db_name' => 'x']] as $params) {
        $group = hc_group_database($params);
        $t->same(1, count($group['rows']), 'unvollständige Angaben dürfen keine Verbindung versuchen');
        $t->same(HC_STATUS_SKIP, $group['rows'][0]['status']);
    }
});

/**
 * The database probes need a real server. Run them by setting
 * HC_TEST_DB_HOST, HC_TEST_DB_NAME, HC_TEST_DB_USER and HC_TEST_DB_PASS –
 * from M1-1 on, the Docker environment of the project provides them.
 */
$t->run('hc_group_database prüft Version, Paketgröße und JSON-Spalten', static function (HcTestRunner $t): void {
    $host = (string) getenv('HC_TEST_DB_HOST');
    if ($host === '') {
        return; // kein Server angegeben, nichts zu prüfen
    }
    $group = hc_group_database([
        'db_host' => $host,
        'db_name' => (string) getenv('HC_TEST_DB_NAME'),
        'db_user' => (string) getenv('HC_TEST_DB_USER'),
        'db_pass' => (string) getenv('HC_TEST_DB_PASS'),
    ]);
    $byId = array_column($group['rows'], 'status', 'id');
    $t->same(HC_STATUS_OK, $byId['db_connect']);
    $t->same(HC_STATUS_OK, $byId['db_create_table'], 'der Installer muss Tabellen anlegen dürfen');
    $t->same(HC_STATUS_OK, $byId['db_json_column']);
    $t->same(HC_STATUS_OK, $byId['db_blob_column']);
    $t->true(isset($byId['db_version']), 'Server-Version fehlt');
    $t->true(isset($byId['db_max_allowed_packet']), 'max_allowed_packet fehlt');
});

$t->run('hc_run_longrun wartet lokal und meldet die Dauer', static function (HcTestRunner $t): void {
    $result = hc_run_longrun(['seconds' => '1']);
    $t->same('local', $result['sub_mode']);
    $t->same(1, $result['requested_seconds']);
    $t->same(true, $result['ok']);
    $t->true($result['elapsed_seconds'] >= 1, 'gemessene Dauer zu kurz');
});

$t->run('hc_run_longrun begrenzt die Wartezeit', static function (HcTestRunner $t): void {
    $t->same(600, hc_run_longrun(['seconds' => '99999', 'mode' => 'remote'])['requested_seconds']);
    $t->same(1, hc_run_longrun(['seconds' => '-5', 'mode' => 'remote'])['requested_seconds']);
});

$t->run('hc_run_longrun verlangt für den Ferntest eine eigene URL', static function (HcTestRunner $t): void {
    $result = hc_run_longrun(['mode' => 'remote', 'seconds' => '30']);
    $t->same('remote', $result['sub_mode']);
    $t->same(false, $result['ok']);
    $t->contains('url', $result['detail'], 'Hinweis auf den fehlenden Parameter');
    $t->notContains('httpbin', $result['detail'], 'kein Fremddienst als Vorgabe');
});

$t->run('hc_run_smtp bricht ohne Host ab und verrät kein Passwort', static function (HcTestRunner $t): void {
    $result = hc_run_smtp(['smtp_pass' => 'geheim', 'smtp_user' => 'post@example.org']);
    $t->same(false, $result['ok']);
    $t->same(false, $result['mail_sent']);
    $t->contains('smtp_host', $result['steps'][0]);
    $t->notContains('geheim', json_encode($result, JSON_THROW_ON_ERROR), 'Passwort darf nie in der Ausgabe stehen');
});

exit($t->report());
