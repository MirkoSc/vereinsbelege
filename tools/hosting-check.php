<?php

declare(strict_types=1);

/**
 * Vereinsbelege – hosting check (milestone M0).
 *
 * Standalone, dependency-free probe that verifies the assumptions the specs
 * make about the shared hosting environment (see docs/spec/06-betrieb.md §5).
 * One single file: no Composer, no vendor/, no autoloader, no framework.
 *
 * Usage on the web space:
 *   1. Set HC_TOKEN below (or the environment variable VEREINSBELEGE_HC_TOKEN).
 *   2. Upload this file into a test directory via FTP.
 *   3. Open https://example.org/test/hosting-check.php?token=<token>
 *   4. Copy the JSON into docs/hosting-befunde.md, then DELETE the file.
 *
 * Usage locally (no token needed on the command line):
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tools/hosting-check.php
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tools/hosting-check.php --format=json
 *
 * Long running probes are separate requests on purpose – running them inside
 * the report would exceed the very request limits we are measuring:
 *   ?test=longrun&seconds=60            how long may a request run at all?
 *   ?test=longrun&mode=remote&seconds=60&url=https://…/delay/60
 *   ?test=stream&mb=200&seconds=60      does a slow 200 MB response arrive?
 *   ?test=smtp&smtp_host=…&smtp_port=465&smtp_secure=implicit
 *
 * Parameters may be sent as POST instead of GET (preferable for SMTP
 * credentials). Nothing is written to disk except throwaway probe files, and
 * no secret is echoed back.
 */

/** Shared secret required for every web request. Empty = script refuses to run. */
const HC_TOKEN = '42b6d104566931cd47f8bb4d2fd8cabb';

const HC_VERSION = '1.0.0';

const HC_STATUS_OK = 'ok';
const HC_STATUS_WARN = 'warn';
const HC_STATUS_FAIL = 'fail';
const HC_STATUS_INFO = 'info';
const HC_STATUS_SKIP = 'skip';

/** Byte sizes used in expectations. */
const HC_MIB = 1048576;

// ---------------------------------------------------------------------------
// Pure helpers (covered by tests/HostingCheckTest.php)
// ---------------------------------------------------------------------------

/**
 * Parses a PHP ini size such as "128M", "1G", "-1" into bytes.
 * Returns null when the value cannot be interpreted, -1 for "unlimited".
 */
function hc_parse_bytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(-?\d+)\s*([kmgt]?)b?$/i', $value, $m)) {
        return null;
    }
    $number = (int) $m[1];
    if ($number < 0) {
        return -1;
    }

    return $number * match (strtolower($m[2])) {
        'k' => 1024,
        'm' => 1024 ** 2,
        'g' => 1024 ** 3,
        't' => 1024 ** 4,
        default => 1,
    };
}

/** Human readable byte size; -1 means unlimited. */
function hc_format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return 'unbekannt';
    }
    if ($bytes < 0) {
        return 'unbegrenzt';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $index = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return ($size === floor($size) ? (string) (int) $size : number_format($size, 1, ',', ''))
        . ' ' . $units[$index];
}

/** Timing-safe token comparison. An unset expectation never matches. */
function hc_token_matches(string $expected, string $given): bool
{
    if ($expected === '' || $given === '') {
        return false;
    }

    return hash_equals($expected, $given);
}

/** Severity order used to fold row statuses into one overall verdict. */
function hc_status_rank(string $status): int
{
    return match ($status) {
        HC_STATUS_FAIL => 4,
        HC_STATUS_WARN => 3,
        HC_STATUS_OK => 2,
        HC_STATUS_SKIP => 1,
        default => 0,
    };
}

/** Builds one result row. */
function hc_row(
    string $id,
    string $label,
    string $value,
    string $expected,
    string $status,
    string $detail = '',
): array {
    return [
        'id' => $id,
        'label' => $label,
        'value' => $value,
        'expected' => $expected,
        'status' => $status,
        'detail' => $detail,
    ];
}

/** ok when $actual >= $min, warn when it is at least $warnMin, fail below. */
function hc_rate_min(?int $actual, int $min, ?int $warnMin = null, bool $unlimitedIsOk = true): string
{
    if ($actual === null) {
        return HC_STATUS_WARN;
    }
    if ($actual < 0) {
        return $unlimitedIsOk ? HC_STATUS_OK : HC_STATUS_WARN;
    }
    if ($actual >= $min) {
        return HC_STATUS_OK;
    }
    if ($warnMin !== null && $actual >= $warnMin) {
        return HC_STATUS_WARN;
    }

    return HC_STATUS_FAIL;
}

/** Counts row statuses and derives the worst one. */
function hc_summarize(array $groups): array
{
    $counts = [
        HC_STATUS_OK => 0,
        HC_STATUS_WARN => 0,
        HC_STATUS_FAIL => 0,
        HC_STATUS_INFO => 0,
        HC_STATUS_SKIP => 0,
    ];
    $worst = HC_STATUS_INFO;
    foreach ($groups as $group) {
        foreach ($group['rows'] as $row) {
            $status = $row['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (hc_status_rank($status) > hc_status_rank($worst)) {
                $worst = $status;
            }
        }
    }

    return $counts + ['worst' => $worst];
}

/** Replaces every value of a sensitive key so it never reaches output. */
function hc_redact(array $params): array
{
    foreach (array_keys($params) as $key) {
        if (preg_match('/(pass|secret|token|key|user|login)/i', (string) $key)) {
            $params[$key] = $params[$key] === '' ? '' : '***';
        }
    }

    return $params;
}

// ---------------------------------------------------------------------------
// Probe groups
// ---------------------------------------------------------------------------

/** PHP runtime and the ini settings the specs rely on. */
function hc_group_php(): array
{
    $rows = [];

    $rows[] = hc_row(
        'php_version',
        'PHP-Version',
        PHP_VERSION,
        '8.5',
        version_compare(PHP_VERSION, '8.5.0', '>=')
            ? HC_STATUS_OK
            : (version_compare(PHP_VERSION, '8.2.0', '>=') ? HC_STATUS_WARN : HC_STATUS_FAIL),
        'Im Kundenmenü ggf. auf die höchste angebotene Version stellen.',
    );
    $rows[] = hc_row('php_sapi', 'SAPI', PHP_SAPI, '–', HC_STATUS_INFO);
    $rows[] = hc_row(
        'php_int_size',
        'Integer-Breite',
        (PHP_INT_SIZE * 8) . ' bit',
        '64 bit',
        PHP_INT_SIZE >= 8 ? HC_STATUS_OK : HC_STATUS_FAIL,
        'Cent-Beträge und Blob-Offsets brauchen 64-bit-Integer.',
    );

    $memoryLimit = hc_parse_bytes((string) ini_get('memory_limit'));
    $rows[] = hc_row(
        'memory_limit',
        'memory_limit',
        hc_format_bytes($memoryLimit),
        '≥ 256 MiB',
        hc_rate_min($memoryLimit, 256 * HC_MIB, 128 * HC_MIB),
        'Argon2id (MODERATE) allein braucht 256 MiB.',
    );

    $maxExecution = (int) ini_get('max_execution_time');
    $rows[] = hc_row(
        'max_execution_time',
        'max_execution_time',
        $maxExecution === 0 ? 'unbegrenzt' : $maxExecution . ' s',
        '≥ 30 s',
        hc_rate_min($maxExecution === 0 ? -1 : $maxExecution, 30, 20),
        'Der echte Abbruch liegt oft beim Webserver – mit ?test=longrun messen.',
    );

    $uploadMax = hc_parse_bytes((string) ini_get('upload_max_filesize'));
    $rows[] = hc_row(
        'upload_max_filesize',
        'upload_max_filesize',
        hc_format_bytes($uploadMax),
        '≥ 2 MiB (Chunk-Upload)',
        hc_rate_min($uploadMax, 2 * HC_MIB),
    );

    $postMax = hc_parse_bytes((string) ini_get('post_max_size'));
    $rows[] = hc_row(
        'post_max_size',
        'post_max_size',
        hc_format_bytes($postMax),
        '≥ 2 MiB (Chunk-Upload)',
        hc_rate_min($postMax, 2 * HC_MIB),
    );

    $maxInput = (int) ini_get('max_input_time');
    $rows[] = hc_row(
        'max_input_time',
        'max_input_time',
        $maxInput <= 0 ? 'unbegrenzt' : $maxInput . ' s',
        '≥ 30 s',
        hc_rate_min($maxInput <= 0 ? -1 : $maxInput, 30, 20),
    );

    $disabled = array_values(array_filter(
        array_map('trim', explode(',', (string) ini_get('disable_functions'))),
        static fn (string $name): bool => $name !== '',
    ));
    $rows[] = hc_row(
        'disable_functions',
        'disable_functions',
        $disabled === [] ? '(leer)' : implode(', ', $disabled),
        'exec & Co. gesperrt ist erwartet',
        HC_STATUS_INFO,
        'Die Anwendung nutzt bewusst keine Kommandozeilen-Werkzeuge.',
    );

    $needed = [
        'curl_exec', 'fsockopen', 'stream_socket_client', 'rename', 'unlink',
        'file_put_contents', 'file_get_contents', 'mail', 'set_time_limit',
        'ignore_user_abort', 'hrtime',
    ];
    $missing = array_values(array_filter(
        $needed,
        static fn (string $name): bool => !function_exists($name),
    ));
    $rows[] = hc_row(
        'required_functions',
        'Benötigte Funktionen',
        $missing === [] ? 'alle vorhanden' : 'fehlen: ' . implode(', ', $missing),
        'alle vorhanden',
        $missing === [] ? HC_STATUS_OK : HC_STATUS_FAIL,
        'Geprüft: ' . implode(', ', $needed),
    );

    $openBasedir = (string) ini_get('open_basedir');
    $rows[] = hc_row(
        'open_basedir',
        'open_basedir',
        $openBasedir === '' ? '(nicht gesetzt)' : $openBasedir,
        'nicht gesetzt oder inkl. Verzeichnis über dem DocumentRoot',
        $openBasedir === '' ? HC_STATUS_OK : HC_STATUS_WARN,
        'shared/ muss außerhalb des DocumentRoot liegen dürfen.',
    );

    $ignoreArgs = (string) ini_get('zend.exception_ignore_args');
    $rows[] = hc_row(
        'exception_ignore_args',
        'zend.exception_ignore_args',
        $ignoreArgs === '' ? '(unbekannt)' : $ignoreArgs,
        'On (1)',
        in_array($ignoreArgs, ['1', 'On', 'on'], true) ? HC_STATUS_OK : HC_STATUS_WARN,
        'Verhindert Passwörter in Stacktraces; sonst per .user.ini setzen.',
    );

    $rows[] = hc_row(
        'allow_url_fopen',
        'allow_url_fopen',
        ini_get('allow_url_fopen') ? 'On' : 'Off',
        'egal (cURL wird bevorzugt)',
        HC_STATUS_INFO,
    );
    $rows[] = hc_row(
        'timezone',
        'Standard-Zeitzone',
        date_default_timezone_get(),
        'egal (Anwendung setzt Europe/Berlin)',
        HC_STATUS_INFO,
    );
    $rows[] = hc_row(
        'opcache',
        'OPcache',
        function_exists('opcache_get_status') ? 'vorhanden' : 'nicht vorhanden',
        'egal',
        HC_STATUS_INFO,
        'Bei Releases per rename() ggf. opcache_reset() nötig.',
    );

    $setTimeLimit = function_exists('set_time_limit')
        && @set_time_limit(((int) ini_get('max_execution_time')) ?: 30);
    $rows[] = hc_row(
        'set_time_limit',
        'set_time_limit() wirkt',
        $setTimeLimit ? 'ja' : 'nein',
        'ja (sonst harte Schrittketten)',
        $setTimeLimit ? HC_STATUS_OK : HC_STATUS_WARN,
    );

    return ['id' => 'php', 'label' => 'PHP-Laufzeit', 'rows' => $rows];
}

/** Extensions listed in the spec, plus the GD format details. */
function hc_group_extensions(): array
{
    $required = [
        'sodium' => 'Verschlüsselung (Tresor, Blobs)',
        'gd' => 'Bild-Fallback auf dem Server',
        'zip' => 'Export- und Release-ZIPs',
        'curl' => 'KI-Anbindung, Updater',
        'openssl' => 'TLS für Mail und HTTPS',
        'mbstring' => 'Textverarbeitung',
        'intl' => 'Formatierung und Sortierung',
        'fileinfo' => 'Upload-Typerkennung',
        'pdo_mysql' => 'Datenbank',
        'iconv' => 'Zeichensatz-Konvertierung (MT940)',
        'json' => 'KI-Antworten, Job-Daten',
    ];
    $optional = [
        'imagick' => 'nicht vorausgesetzt (Bildarbeit läuft im Browser)',
        'zlib' => 'Komprimierung im Backup',
        'exif' => 'Bildorientierung',
    ];

    $rows = [];
    foreach ($required as $name => $why) {
        $loaded = extension_loaded($name);
        $rows[] = hc_row(
            'ext_' . $name,
            'ext-' . $name,
            $loaded ? 'vorhanden' : 'FEHLT',
            'vorhanden',
            $loaded ? HC_STATUS_OK : HC_STATUS_FAIL,
            $why,
        );
    }
    foreach ($optional as $name => $why) {
        $rows[] = hc_row(
            'ext_' . $name,
            'ext-' . $name,
            extension_loaded($name) ? 'vorhanden' : 'nicht vorhanden',
            'optional',
            HC_STATUS_INFO,
            $why,
        );
    }

    if (extension_loaded('gd') && function_exists('gd_info')) {
        $info = gd_info();
        $supported = [];
        $missing = [];
        foreach (['JPEG' => 'JPEG Support', 'PNG' => 'PNG Support', 'WebP' => 'WebP Support', 'AVIF' => 'AVIF Support'] as $label => $key) {
            if (!empty($info[$key])) {
                $supported[] = $label;
            } else {
                $missing[] = $label;
            }
        }
        $jpegPng = !empty($info['JPEG Support']) && !empty($info['PNG Support']);
        $rows[] = hc_row(
            'gd_formats',
            'GD-Formate',
            ($supported === [] ? 'keine' : implode(', ', $supported))
                . ($missing === [] ? '' : ' (ohne ' . implode(', ', $missing) . ')'),
            'JPEG + PNG',
            $jpegPng ? HC_STATUS_OK : HC_STATUS_FAIL,
            'GD-Version: ' . (string) ($info['GD Version'] ?? 'unbekannt'),
        );
    } else {
        $rows[] = hc_row('gd_formats', 'GD-Formate', 'nicht prüfbar', 'JPEG + PNG', HC_STATUS_SKIP, 'ext-gd fehlt.');
    }

    return ['id' => 'extensions', 'label' => 'Erweiterungen', 'rows' => $rows];
}

/** Argon2id timings and the two libsodium primitives the vault model needs. */
function hc_group_crypto(): array
{
    $rows = [];

    if (!extension_loaded('sodium')) {
        $rows[] = hc_row(
            'sodium_version',
            'libsodium',
            'FEHLT',
            'vorhanden',
            HC_STATUS_FAIL,
            'Ohne sodium ist das Tresor-Modell nicht umsetzbar.',
        );

        return ['id' => 'crypto', 'label' => 'Verschlüsselung', 'rows' => $rows];
    }

    $rows[] = hc_row(
        'sodium_version',
        'libsodium-Version',
        SODIUM_LIBRARY_VERSION,
        '≥ 1.0.18',
        version_compare(SODIUM_LIBRARY_VERSION, '1.0.18', '>=') ? HC_STATUS_OK : HC_STATUS_WARN,
    );

    $memoryLimit = hc_parse_bytes((string) ini_get('memory_limit'));
    $profiles = [
        'interactive' => [
            'label' => 'sodium_crypto_pwhash INTERACTIVE',
            'ops' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            'mem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            'budget' => 1000,
        ],
        'moderate' => [
            'label' => 'sodium_crypto_pwhash MODERATE',
            'ops' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            'mem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            'budget' => 3000,
        ],
    ];
    foreach ($profiles as $key => $profile) {
        $needed = $profile['mem'] + 32 * HC_MIB;
        if ($memoryLimit !== null && $memoryLimit >= 0 && $memoryLimit < $needed) {
            $rows[] = hc_row(
                'pwhash_' . $key,
                $profile['label'],
                'übersprungen',
                '< ' . $profile['budget'] . ' ms',
                HC_STATUS_SKIP,
                'memory_limit ' . hc_format_bytes($memoryLimit) . ' liegt unter den benötigten '
                    . hc_format_bytes($needed) . '.',
            );
            continue;
        }
        try {
            $start = hrtime(true);
            sodium_crypto_pwhash(
                32,
                'hosting-check',
                random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
                $profile['ops'],
                $profile['mem'],
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
            $ms = (int) round((hrtime(true) - $start) / 1000000);
            $rows[] = hc_row(
                'pwhash_' . $key,
                $profile['label'],
                $ms . ' ms',
                '< ' . $profile['budget'] . ' ms',
                $ms < $profile['budget'] ? HC_STATUS_OK : HC_STATUS_WARN,
                'Speicher ' . hc_format_bytes($profile['mem']) . ', Durchläufe ' . $profile['ops'],
            );
        } catch (Throwable $e) {
            $rows[] = hc_row(
                'pwhash_' . $key,
                $profile['label'],
                'Fehler',
                '< ' . $profile['budget'] . ' ms',
                HC_STATUS_FAIL,
                $e::class . ': ' . $e->getMessage(),
            );
        }
    }

    if (defined('PASSWORD_ARGON2ID')) {
        try {
            $start = hrtime(true);
            $hash = password_hash('hosting-check', PASSWORD_ARGON2ID);
            $ms = (int) round((hrtime(true) - $start) / 1000000);
            $verified = password_verify('hosting-check', $hash);
            $rows[] = hc_row(
                'password_argon2id',
                'password_hash(PASSWORD_ARGON2ID)',
                $verified ? 'funktioniert (' . $ms . ' ms)' : 'Hash nicht prüfbar',
                'funktioniert',
                $verified ? HC_STATUS_OK : HC_STATUS_FAIL,
            );
        } catch (Throwable $e) {
            $rows[] = hc_row(
                'password_argon2id',
                'password_hash(PASSWORD_ARGON2ID)',
                'Fehler',
                'funktioniert',
                HC_STATUS_FAIL,
                $e::class . ': ' . $e->getMessage(),
            );
        }
    } else {
        $rows[] = hc_row(
            'password_argon2id',
            'password_hash(PASSWORD_ARGON2ID)',
            'nicht verfügbar',
            'verfügbar',
            HC_STATUS_WARN,
            'Ersatz wäre sodium_crypto_pwhash_str(); dann 01-sicherheit.md nachziehen.',
        );
    }

    $rows[] = hc_probe_primitive('sealed_box', 'Sealed Box (Tresor-Modell)', static function (): bool {
        $pair = sodium_crypto_box_keypair();
        $sealed = sodium_crypto_box_seal('probe', sodium_crypto_box_publickey($pair));

        return sodium_crypto_box_seal_open($sealed, $pair) === 'probe';
    });

    $rows[] = hc_probe_primitive('secretstream', 'secretstream (Blob-Verschlüsselung)', static function (): bool {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $chunk = sodium_crypto_secretstream_xchacha20poly1305_push($state, 'blob');
        $pull = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        [$plain] = sodium_crypto_secretstream_xchacha20poly1305_pull($pull, $chunk);

        return $plain === 'blob';
    });

    $rows[] = hc_probe_primitive('random_bytes', 'random_bytes()', static function (): bool {
        return strlen(random_bytes(32)) === 32;
    });

    return ['id' => 'crypto', 'label' => 'Verschlüsselung', 'rows' => $rows];
}

/** Runs a crypto round trip and turns its outcome into a row. */
function hc_probe_primitive(string $id, string $label, callable $probe): array
{
    try {
        $ok = (bool) $probe();

        return hc_row(
            $id,
            $label,
            $ok ? 'funktioniert' : 'falsches Ergebnis',
            'funktioniert',
            $ok ? HC_STATUS_OK : HC_STATUS_FAIL,
        );
    } catch (Throwable $e) {
        return hc_row($id, $label, 'Fehler', 'funktioniert', HC_STATUS_FAIL, $e::class . ': ' . $e->getMessage());
    }
}

/**
 * Database probes. Skipped unless credentials are passed in, e.g.
 * ?db_host=localhost&db_name=x&db_user=y&db_pass=z
 */
function hc_group_database(array $params): array
{
    $label = 'Datenbank';
    $host = (string) ($params['db_host'] ?? '');
    $name = (string) ($params['db_name'] ?? '');
    $user = (string) ($params['db_user'] ?? '');
    $pass = (string) ($params['db_pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        return ['id' => 'database', 'label' => $label, 'rows' => [hc_row(
            'db_connect',
            'Verbindung',
            'übersprungen',
            'Verbindung steht',
            HC_STATUS_SKIP,
            'Mit db_host, db_name, db_user, db_pass aufrufen (am besten per POST).',
        )]];
    }

    $rows = [];
    $port = (int) ($params['db_port'] ?? 3306);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    } catch (Throwable $e) {
        return ['id' => 'database', 'label' => $label, 'rows' => [hc_row(
            'db_connect',
            'Verbindung',
            'fehlgeschlagen',
            'Verbindung steht',
            HC_STATUS_FAIL,
            $e::class . ': ' . $e->getMessage(),
        )]];
    }

    $rows[] = hc_row('db_connect', 'Verbindung', 'steht', 'Verbindung steht', HC_STATUS_OK);

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = stripos($version, 'mariadb') !== false;
    $numeric = preg_match('/(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : '0.0.0';
    $rows[] = hc_row(
        'db_version',
        'Server-Version',
        $version,
        'MariaDB ≥ 10.5 oder MySQL ≥ 8.0',
        version_compare($numeric, $isMaria ? '10.5.0' : '8.0.0', '>=') ? HC_STATUS_OK : HC_STATUS_WARN,
    );

    foreach ([
        'max_allowed_packet' => ['label' => 'max_allowed_packet', 'expected' => '≥ 4 MiB', 'min' => 4 * HC_MIB],
        'wait_timeout' => [
            'label' => 'wait_timeout',
            'expected' => 'lange Schritte beachten',
            'min' => null,
            'detail' => 'Untätige Verbindungen brechen danach ab. Nach langen externen '
                . 'Aufrufen (KI) muss die Verbindung neu aufgebaut werden.',
        ],
        'character_set_database' => ['label' => 'Zeichensatz', 'expected' => 'utf8mb4', 'min' => null],
        'default_storage_engine' => ['label' => 'Standard-Speicher-Engine', 'expected' => 'InnoDB', 'min' => null],
    ] as $variable => $spec) {
        // SHOW VARIABLES takes no placeholder; the names come from the list above.
        $statement = $pdo->query('SHOW VARIABLES LIKE ' . $pdo->quote($variable));
        $value = (string) ($statement->fetch(PDO::FETCH_ASSOC)['Value'] ?? '');
        if ($variable === 'max_allowed_packet') {
            $rows[] = hc_row(
                'db_max_allowed_packet',
                $spec['label'],
                hc_format_bytes($value === '' ? null : (int) $value),
                $spec['expected'],
                hc_rate_min($value === '' ? null : (int) $value, (int) $spec['min']),
                'Begrenzt die Blob-Chunk-Größe im Backend "db".',
            );
            continue;
        }
        if ($variable === 'character_set_database') {
            $rows[] = hc_row(
                'db_charset',
                $spec['label'],
                $value === '' ? 'unbekannt' : $value,
                'utf8mb4',
                $value === 'utf8mb4' ? HC_STATUS_OK : HC_STATUS_WARN,
            );
            continue;
        }
        $rows[] = hc_row(
            'db_' . $variable,
            $spec['label'],
            $value === '' ? 'unbekannt' : $value . ($variable === 'wait_timeout' ? ' s' : ''),
            $spec['expected'],
            HC_STATUS_INFO,
            (string) ($spec['detail'] ?? ''),
        );
    }

    $probeTable = 'hc_probe_' . bin2hex(random_bytes(6));
    try {
        $pdo->exec(sprintf(
            'CREATE TABLE `%s` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'payload JSON NOT NULL, blob_chunk LONGBLOB NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $probeTable,
        ));
        $insert = $pdo->prepare(sprintf('INSERT INTO `%s` (payload, blob_chunk) VALUES (?, ?)', $probeTable));
        $insert->execute(['{"probe":true,"n":42}', random_bytes(1024)]);
        // MariaDB does not accept a placeholder as JSON path, so the literal is quoted.
        $extracted = $pdo->query(sprintf(
            'SELECT JSON_EXTRACT(payload, %s) AS n, LENGTH(blob_chunk) AS len FROM `%s` LIMIT 1',
            $pdo->quote('$.n'),
            $probeTable,
        ))->fetch(PDO::FETCH_ASSOC);
        $jsonOk = (int) ($extracted['n'] ?? 0) === 42;
        $blobOk = (int) ($extracted['len'] ?? 0) === 1024;
        $rows[] = hc_row(
            'db_create_table',
            'Tabelle anlegen (Installer)',
            'funktioniert',
            'funktioniert',
            HC_STATUS_OK,
        );
        $rows[] = hc_row(
            'db_json_column',
            'JSON-Spalte + JSON_EXTRACT',
            $jsonOk ? 'funktioniert' : 'falsches Ergebnis',
            'funktioniert',
            $jsonOk ? HC_STATUS_OK : HC_STATUS_FAIL,
        );
        $rows[] = hc_row(
            'db_blob_column',
            'LONGBLOB schreiben/lesen',
            $blobOk ? 'funktioniert' : 'falsches Ergebnis',
            'funktioniert',
            $blobOk ? HC_STATUS_OK : HC_STATUS_FAIL,
        );
    } catch (Throwable $e) {
        $rows[] = hc_row(
            'db_create_table',
            'Tabelle anlegen (Installer)',
            'fehlgeschlagen',
            'funktioniert',
            HC_STATUS_FAIL,
            $e::class . ': ' . $e->getMessage(),
        );
    } finally {
        try {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $probeTable));
        } catch (Throwable) {
            // nothing we can do about it here; the table name is in the output
        }
    }

    $rows[] = hc_row(
        'db_size_limit',
        'Größenlimit im Tarif',
        'manuell nachsehen',
        'im Kundenmenü ablesen',
        HC_STATUS_INFO,
        'Bestimmt, ob das Blob-Backend "db" überhaupt in Frage kommt.',
    );

    return ['id' => 'database', 'label' => $label, 'rows' => $rows];
}

/** Outbound HTTPS reachability. Disable with outbound=0. */
function hc_group_outbound(array $params): array
{
    $rows = [];
    if (($params['outbound'] ?? '1') === '0') {
        $rows[] = hc_row('outbound', 'Ausgehende Verbindungen', 'übersprungen', 'erreichbar', HC_STATUS_SKIP, 'outbound=0 gesetzt.');

        return ['id' => 'outbound', 'label' => 'Ausgehende Verbindungen', 'rows' => $rows];
    }

    foreach (hc_outbound_targets($params) as $key => $target) {
        $result = hc_http_probe($target['url'], 15);
        $rows[] = hc_row(
            'outbound_' . $key,
            $target['label'],
            $result['ok']
                ? 'HTTP ' . $result['status'] . ' nach ' . $result['ms'] . ' ms'
                : 'nicht erreichbar',
            'antwortet (ein 401 ohne Schlüssel genügt)',
            $result['ok'] ? HC_STATUS_OK : HC_STATUS_FAIL,
            $result['detail'],
        );
    }

    return ['id' => 'outbound', 'label' => 'Ausgehende Verbindungen', 'rows' => $rows];
}

/**
 * Hosts probed for reachability. An own llm_url is added when given; no other
 * third party host is ever contacted.
 *
 * @return array<string, array{label:string, url:string}>
 */
function hc_outbound_targets(array $params): array
{
    $targets = [
        'openai' => ['label' => 'api.openai.com', 'url' => 'https://api.openai.com/v1/models'],
        'anthropic' => ['label' => 'api.anthropic.com', 'url' => 'https://api.anthropic.com/v1/models'],
        'github' => ['label' => 'api.github.com (Updater)', 'url' => 'https://api.github.com/rate_limit'],
    ];
    $own = trim((string) ($params['llm_url'] ?? ''));
    if ($own !== '') {
        $targets['own'] = ['label' => 'eigener KI-Server', 'url' => $own];
    }

    return $targets;
}

/**
 * Single GET request without any credentials. Uses cURL when available and
 * falls back to a TLS stream. Any HTTP answer counts as reachable.
 *
 * @return array{ok:bool,status:int,ms:int,detail:string}
 */
function hc_http_probe(string $url, int $timeout): array
{
    $start = hrtime(true);
    $elapsed = static fn (int $start): int => (int) round((hrtime(true) - $start) / 1000000);

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['ok' => false, 'status' => 0, 'ms' => 0, 'detail' => 'curl_init() fehlgeschlagen'];
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'vereinsbelege-hosting-check/' . HC_VERSION,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $tls = curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT) === 0
            ? 'Zertifikat ok'
            : 'Zertifikat nicht geprüft';
        unset($handle);

        if ($body === false || $status === 0) {
            return ['ok' => false, 'status' => 0, 'ms' => $elapsed($start), 'detail' => 'cURL: ' . $error];
        }

        return ['ok' => true, 'status' => $status, 'ms' => $elapsed($start), 'detail' => 'via cURL, ' . $tls];
    }

    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        return ['ok' => false, 'status' => 0, 'ms' => 0, 'detail' => 'URL nicht lesbar'];
    }
    $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));
    $transport = ($parts['scheme'] ?? 'https') === 'https' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    if (!is_resource($socket)) {
        return ['ok' => false, 'status' => 0, 'ms' => $elapsed($start), 'detail' => 'Socket: ' . $errstr . ' (' . $errno . ')'];
    }
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    fwrite($socket, "GET " . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n"
        . "User-Agent: vereinsbelege-hosting-check/" . HC_VERSION . "\r\n\r\n");
    stream_set_timeout($socket, $timeout);
    $statusLine = (string) fgets($socket, 256);
    fclose($socket);
    $status = preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $statusLine, $m) === 1 ? (int) $m[1] : 0;

    return [
        'ok' => $status > 0,
        'status' => $status,
        'ms' => $elapsed($start),
        'detail' => 'via stream_socket_client (kein cURL vorhanden)',
    ];
}

/**
 * Write access, rename() of directories and the relation of the script to the
 * DocumentRoot. Everything it creates is removed again.
 */
function hc_group_filesystem(array $params): array
{
    $rows = [];
    $scriptDir = __DIR__;
    $base = trim((string) ($params['fsdir'] ?? ''));
    if ($base === '') {
        $base = dirname($scriptDir);
    }
    $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

    $rows[] = hc_row('fs_script_dir', 'Skript-Verzeichnis', $scriptDir, '–', HC_STATUS_INFO);
    $rows[] = hc_row(
        'fs_document_root',
        'DocumentRoot',
        $documentRoot === '' ? '(unbekannt, CLI)' : $documentRoot,
        '–',
        HC_STATUS_INFO,
    );

    $realRoot = $documentRoot === '' ? false : realpath($documentRoot);
    if ($realRoot === false) {
        $rows[] = hc_row(
            'fs_above_document_root',
            'Prüfpfad liegt über dem DocumentRoot',
            'nicht prüfbar',
            'ja (shared/ darf nicht öffentlich sein)',
            HC_STATUS_SKIP,
            'Nur im Webaufruf feststellbar; auf der Kommandozeile gibt es keinen DocumentRoot.',
        );
    } else {
        $realBase = realpath($base) ?: $base;
        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);
        // Equal paths mean the probe ran in the DocumentRoot itself, which is
        // public – that is not "above" it.
        $isRoot = $realBase === $realRoot;
        $inside = $isRoot || str_starts_with($realBase, $realRoot . DIRECTORY_SEPARATOR);
        $rows[] = hc_row(
            'fs_above_document_root',
            'Prüfpfad liegt über dem DocumentRoot',
            $inside ? ($isRoot ? 'nein, es ist der DocumentRoot selbst' : 'nein, er liegt darin') : 'ja',
            'ja (shared/ darf nicht öffentlich sein)',
            $inside ? HC_STATUS_WARN : HC_STATUS_OK,
            'Geprüfter Pfad: ' . $realBase . ' – mit fsdir=/pfad auf das Verzeichnis '
                . 'zeigen, in dem später shared/ liegen soll.',
        );
    }

    $writable = is_dir($base) && is_writable($base);
    $rows[] = hc_row(
        'fs_writable',
        'Schreibrecht im Prüfpfad',
        $writable ? 'vorhanden' : 'fehlt',
        'vorhanden',
        $writable ? HC_STATUS_OK : HC_STATUS_FAIL,
        $base,
    );

    $probe = $base . DIRECTORY_SEPARATOR . 'hc_probe_' . bin2hex(random_bytes(6));
    $renamed = $probe . '_moved';
    try {
        if (!@mkdir($probe, 0775)) {
            $rows[] = hc_row('fs_mkdir', 'Verzeichnis anlegen', 'fehlgeschlagen', 'funktioniert', HC_STATUS_FAIL, $probe);
        } else {
            $rows[] = hc_row('fs_mkdir', 'Verzeichnis anlegen', 'funktioniert', 'funktioniert', HC_STATUS_OK);
            $file = $probe . DIRECTORY_SEPARATOR . 'probe.txt';
            $written = @file_put_contents($file, 'hosting-check') !== false
                && @file_get_contents($file) === 'hosting-check';
            $rows[] = hc_row(
                'fs_write_file',
                'Datei schreiben und lesen',
                $written ? 'funktioniert' : 'fehlgeschlagen',
                'funktioniert',
                $written ? HC_STATUS_OK : HC_STATUS_FAIL,
            );

            $lockHandle = @fopen($file, 'cb');
            $locked = is_resource($lockHandle) && @flock($lockHandle, LOCK_EX | LOCK_NB);
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
            $rows[] = hc_row(
                'fs_flock',
                'flock()',
                $locked ? 'funktioniert' : 'fehlgeschlagen',
                'funktioniert',
                $locked ? HC_STATUS_OK : HC_STATUS_WARN,
                'Wird für die Job-Sperre und den Updater gebraucht.',
            );

            $renameOk = @rename($probe, $renamed);
            $rows[] = hc_row(
                'fs_rename_dir',
                'rename() eines Verzeichnisses',
                $renameOk ? 'funktioniert' : 'fehlgeschlagen',
                'funktioniert',
                $renameOk ? HC_STATUS_OK : HC_STATUS_FAIL,
                'Release-Umschaltung: /releases/vX.Y.Z nach /current.',
            );
            if ($renameOk) {
                $renameBack = @rename($renamed, $probe);
                $rows[] = hc_row(
                    'fs_rename_back',
                    'rename() zurück (Rollback)',
                    $renameBack ? 'funktioniert' : 'fehlgeschlagen',
                    'funktioniert',
                    $renameBack ? HC_STATUS_OK : HC_STATUS_WARN,
                );
            }

            $link = $probe . DIRECTORY_SEPARATOR . 'link';
            $symlinkOk = @symlink($file, $link);
            if ($symlinkOk) {
                @unlink($link);
            }
            $rows[] = hc_row(
                'fs_symlink',
                'symlink()',
                $symlinkOk ? 'funktioniert' : 'nicht möglich',
                'optional (rename() genügt)',
                HC_STATUS_INFO,
            );
        }
    } finally {
        hc_remove_tree($probe);
        hc_remove_tree($renamed);
    }

    $tmp = sys_get_temp_dir();
    $rows[] = hc_row(
        'fs_tmp',
        'sys_get_temp_dir() beschreibbar',
        is_writable($tmp) ? 'ja' : 'nein',
        'ja',
        is_writable($tmp) ? HC_STATUS_OK : HC_STATUS_WARN,
        $tmp,
    );

    $free = @disk_free_space($base);
    $rows[] = hc_row(
        'fs_free_space',
        'Freier Speicher im Prüfpfad',
        $free === false ? 'unbekannt' : hc_format_bytes((int) $free),
        '–',
        HC_STATUS_INFO,
    );

    return ['id' => 'filesystem', 'label' => 'Dateisystem', 'rows' => $rows];
}

/** Removes a probe directory including its contents. */
function hc_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        hc_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

/**
 * Builds the public URL of a path below the script directory, derived from the
 * request itself. Returns null when the request data is unusable.
 */
function hc_public_url_for(string $relative, array $server): ?string
{
    $host = (string) ($server['HTTP_HOST'] ?? '');
    if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
        return null; // kein brauchbarer Hostname, kein Selbstaufruf
    }
    $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
    if ($scriptName === '' || !str_starts_with($scriptName, '/')) {
        return null;
    }
    $https = (string) ($server['HTTPS'] ?? '');
    $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
    $base = substr($scriptName, 0, (int) strrpos($scriptName, '/'));

    return $scheme . '://' . $host . $base . '/' . ltrim($relative, '/');
}

/** The portable snippet that denies web access to a directory. */
function hc_htaccess_deny(): string
{
    return <<<'HTACCESS'
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        HTACCESS;
}

/**
 * Are .htaccess directives evaluated? Everything in the DocumentRoot depends
 * on it: the security headers and the CSP, and – if the DocumentRoot cannot be
 * moved into web/ – whether shared/ can be shielded at all.
 *
 * Works by writing a probe file next to this script, fetching it over HTTP
 * (expecting 200), then denying it via .htaccess and fetching again
 * (expecting anything but 200).
 */
function hc_group_htaccess(array $params): array
{
    $label = '.htaccess';
    $skip = static fn (string $why): array => ['id' => 'htaccess', 'label' => $label, 'rows' => [hc_row(
        'htaccess_deny',
        '.htaccess sperrt ein Verzeichnis',
        'übersprungen',
        'Zugriff wird verweigert',
        HC_STATUS_SKIP,
        $why,
    )]];

    if (PHP_SAPI === 'cli') {
        return $skip('Nur im Webaufruf prüfbar.');
    }
    if (PHP_SAPI === 'cli-server') {
        return $skip('Der eingebaute PHP-Server kennt keine .htaccess und verarbeitet nur einen Request.');
    }
    if (($params['selftest'] ?? '1') === '0') {
        return $skip('selftest=0 gesetzt.');
    }

    $name = 'hc_probe_' . bin2hex(random_bytes(6));
    $dir = __DIR__ . DIRECTORY_SEPARATOR . $name;
    $url = hc_public_url_for($name . '/probe.txt', $_SERVER ?? []);
    if ($url === null) {
        return $skip('Die öffentliche Adresse des Skripts ließ sich nicht bestimmen.');
    }
    if (!@mkdir($dir, 0775)) {
        return $skip('Im Skript-Verzeichnis ließ sich kein Prüfverzeichnis anlegen.');
    }

    $rows = [];
    try {
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'probe.txt', 'hosting-check');
        $before = hc_http_probe($url, 8);
        $rows[] = hc_row(
            'htaccess_reachable',
            'Datei neben dem Skript ist öffentlich abrufbar',
            $before['ok'] ? 'HTTP ' . $before['status'] : 'nicht erreichbar',
            'HTTP 200 (belegt, dass die Prüfung etwas aussagt)',
            $before['status'] === 200 ? HC_STATUS_OK : HC_STATUS_WARN,
            $before['ok']
                ? $before['detail']
                : 'Der Server hat seine eigene Adresse ' . $url . ' nicht erreicht. Das sagt '
                    . 'nichts über .htaccess – die Sperre dann von Hand prüfen. (' . $before['detail'] . ')',
        );
        if ($before['status'] !== 200) {
            $rows[] = hc_row(
                'htaccess_deny',
                '.htaccess sperrt ein Verzeichnis',
                'nicht prüfbar',
                'Zugriff wird verweigert',
                HC_STATUS_SKIP,
                'Ohne erfolgreichen Erstabruf sagt die Sperre nichts aus.',
            );

            return ['id' => 'htaccess', 'label' => $label, 'rows' => $rows];
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', hc_htaccess_deny());
        $after = hc_http_probe($url, 8);
        $denied = $after['ok'] && $after['status'] !== 200;
        $rows[] = hc_row(
            'htaccess_deny',
            '.htaccess sperrt ein Verzeichnis',
            $after['ok'] ? 'HTTP ' . $after['status'] : 'keine Antwort',
            'Zugriff wird verweigert (403)',
            $denied ? HC_STATUS_OK : HC_STATUS_FAIL,
            $denied
                ? 'AllowOverride ist aktiv: Security-Header, CSP und ein Schutz für shared/ greifen.'
                : 'Die Datei war trotz .htaccess weiter abrufbar – Security-Header und CSP '
                    . 'müssen dann anders gesetzt werden, und shared/ darf nicht im '
                    . 'DocumentRoot liegen.',
        );
    } finally {
        hc_remove_tree($dir);
    }

    return ['id' => 'htaccess', 'label' => $label, 'rows' => $rows];
}

/** Facts that cannot be probed and have to be read from the control panel. */
function hc_group_notes(): array
{
    return ['id' => 'notes', 'label' => 'Manuell nachsehen / eigene Messung', 'rows' => [
        hc_row(
            'cron_interval',
            'Kleinstes Cron-Intervall',
            'manuell nachsehen',
            '5–15 min',
            HC_STATUS_INFO,
            'Kundenmenü, Bereich Cronjobs. Bestimmt die Verzögerung beim Mail-Versand.',
        ),
        hc_row(
            'shared_reachable',
            'Ist der Pfad über dem DocumentRoot öffentlich?',
            'manuell prüfen',
            'nein (sonst .htaccess-Schutz nötig)',
            HC_STATUS_INFO,
            'Der Check kennt nur den DocumentRoot der aufgerufenen Domain. Bei '
                . 'all-inkl ist das Verzeichnis darüber häufig der DocumentRoot der '
                . 'Hauptdomain. Probe: eine Testdatei dort ablegen und über die '
                . 'Hauptdomain abrufen. Erreichbar? Dann shared/ zusätzlich per '
                . '.htaccess sperren.',
        ),
        hc_row(
            'php_version_switch',
            'PHP-Version umstellbar',
            'manuell nachsehen',
            'ja',
            HC_STATUS_INFO,
            'Kundenmenü, PHP-Version je Domain.',
        ),
        hc_row(
            'longrun_limit',
            'Harter Request-Abbruch',
            'mit ?test=longrun messen',
            '> 90 s',
            HC_STATUS_INFO,
            'Pro Messung ein Aufruf: ?test=longrun&seconds=30 bzw. 60, 90, 120, 180.',
        ),
        hc_row(
            'stream_limit',
            'Langsame große Antwort',
            'mit ?test=stream messen',
            '200 MB über 60 s kommen an',
            HC_STATUS_INFO,
            '?test=stream&mb=200&seconds=60',
        ),
        hc_row(
            'smtp',
            'SMTP-Zugang',
            'mit ?test=smtp messen',
            'Port 465 (implizit) oder 587 (STARTTLS)',
            HC_STATUS_INFO,
            '?test=smtp&smtp_host=...&smtp_port=465&smtp_secure=implicit – besser per POST.',
        ),
    ]];
}

/** Builds the full report. */
function hc_build_report(array $params): array
{
    $groups = [
        hc_group_php(),
        hc_group_extensions(),
        hc_group_crypto(),
        hc_group_database($params),
        hc_group_outbound($params),
        hc_group_filesystem($params),
        hc_group_htaccess($params),
        hc_group_notes(),
    ];

    return [
        'tool' => 'vereinsbelege-hosting-check',
        'version' => HC_VERSION,
        'generated_at' => date('c'),
        'mode' => 'report',
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? php_uname('n')),
        'summary' => hc_summarize($groups),
        'groups' => $groups,
    ];
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/** Short marker in front of every row. */
function hc_status_symbol(string $status): string
{
    return match ($status) {
        HC_STATUS_OK => '[ ok ]',
        HC_STATUS_WARN => '[warn]',
        HC_STATUS_FAIL => '[FAIL]',
        HC_STATUS_SKIP => '[skip]',
        default => '[info]',
    };
}

/** Plain text table, used on the command line. */
function hc_render_text(array $report): string
{
    $lines = [];
    $lines[] = 'Vereinsbelege – Hosting-Check ' . $report['version'];
    $lines[] = 'Host: ' . $report['host'] . ' – ' . $report['generated_at'];
    $summary = $report['summary'];
    $lines[] = sprintf(
        'Ergebnis: %s (ok %d, warn %d, fail %d, info %d, übersprungen %d)',
        strtoupper($summary['worst']),
        $summary[HC_STATUS_OK],
        $summary[HC_STATUS_WARN],
        $summary[HC_STATUS_FAIL],
        $summary[HC_STATUS_INFO],
        $summary[HC_STATUS_SKIP],
    );

    foreach ($report['groups'] as $group) {
        $lines[] = '';
        $lines[] = '== ' . $group['label'] . ' ' . str_repeat('=', max(1, 60 - strlen($group['label'])));
        $labelWidth = 0;
        $valueWidth = 0;
        foreach ($group['rows'] as $row) {
            $labelWidth = max($labelWidth, mb_strlen($row['label']));
            $valueWidth = max($valueWidth, mb_strlen($row['value']));
        }
        $labelWidth = min($labelWidth, 34);
        $valueWidth = min($valueWidth, 40);
        foreach ($group['rows'] as $row) {
            $lines[] = sprintf(
                '%s %s  %s  %s',
                hc_status_symbol($row['status']),
                hc_pad($row['label'], $labelWidth),
                hc_pad($row['value'], $valueWidth),
                'Annahme: ' . $row['expected'],
            );
            if ($row['detail'] !== '') {
                $lines[] = str_repeat(' ', 7) . '- ' . $row['detail'];
            }
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/** Pads or truncates a value to a fixed display width. */
function hc_pad(string $value, int $width): string
{
    $length = mb_strlen($value);
    if ($length > $width) {
        return mb_substr($value, 0, max(1, $width - 1)) . '…';
    }

    return $value . str_repeat(' ', $width - $length);
}

/** Pretty printed JSON for docs/hosting-befunde.md. */
function hc_render_json(array $report): string
{
    return json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
}

/** Self-contained HTML page: table on desktop, stacked cards below 560 px. */
function hc_render_html(array $report): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $summary = $report['summary'];

    $out = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Vereinsbelege – Hosting-Check</title><style>'
        . ':root{color-scheme:light dark;--ok:#1a7f37;--warn:#9a6700;--fail:#b3261e;--info:#57606a;--line:#d0d7de}'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;padding:1rem;font:16px/1.5 system-ui,sans-serif;max-width:60rem}'
        . 'h1{font-size:1.3rem;margin:0 0 .25rem}h2{font-size:1.05rem;margin:1.75rem 0 .5rem}'
        . 'p{margin:.25rem 0}code,pre,textarea{font-family:ui-monospace,monospace}'
        . 'table{width:100%;border-collapse:collapse;font-size:.95rem}'
        . 'th,td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}'
        . 'th{font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;color:var(--info)}'
        . '.s{font-weight:700;white-space:nowrap}.s-ok{color:var(--ok)}.s-warn{color:var(--warn)}'
        . '.s-fail{color:var(--fail)}.s-info,.s-skip{color:var(--info)}'
        . '.d{display:block;font-size:.85rem;color:var(--info);margin-top:.15rem}'
        . '.v{word-break:break-word}'
        . 'textarea{width:100%;min-height:14rem;border:1px solid var(--line);border-radius:.3rem;padding:.5rem}'
        . '.sum{border:1px solid var(--line);border-radius:.4rem;padding:.5rem .75rem;margin:.75rem 0}'
        . '@media (max-width:560px){thead{display:none}tr{display:block;border-bottom:1px solid var(--line);padding:.5rem 0}'
        . 'td{display:block;border:0;padding:.1rem 0}td::before{content:attr(data-label) ": ";color:var(--info);font-size:.8rem}'
        . 'td.s::before{content:""}}'
        . '</style></head><body>';

    $out .= '<h1>Vereinsbelege – Hosting-Check</h1>';
    $out .= '<p>' . $e($report['host']) . ' · ' . $e($report['generated_at'])
        . ' · Skript-Version ' . $e($report['version']) . '</p>';
    $out .= '<div class="sum"><strong class="s s-' . $e($summary['worst']) . '">'
        . 'Gesamt: ' . $e(strtoupper($summary['worst'])) . '</strong><br>'
        . sprintf(
            'ok %d · warn %d · fail %d · info %d · übersprungen %d',
            $summary[HC_STATUS_OK],
            $summary[HC_STATUS_WARN],
            $summary[HC_STATUS_FAIL],
            $summary[HC_STATUS_INFO],
            $summary[HC_STATUS_SKIP],
        )
        . '</div>';

    foreach ($report['groups'] as $group) {
        $out .= '<h2>' . $e($group['label']) . '</h2>';
        $out .= '<table><thead><tr><th>Status</th><th>Prüfpunkt</th><th>Befund</th><th>Annahme</th></tr></thead><tbody>';
        foreach ($group['rows'] as $row) {
            $out .= '<tr>'
                . '<td class="s s-' . $e($row['status']) . '">' . $e(hc_status_symbol($row['status'])) . '</td>'
                . '<td data-label="Prüfpunkt">' . $e($row['label']) . '</td>'
                . '<td class="v" data-label="Befund">' . $e($row['value'])
                . ($row['detail'] !== '' ? '<span class="d">' . $e($row['detail']) . '</span>' : '')
                . '</td>'
                . '<td data-label="Annahme">' . $e($row['expected']) . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';
    }

    $out .= '<h2>JSON für docs/hosting-befunde.md</h2>'
        . '<p>Markieren und kopieren, oder <code>&amp;format=json</code> an die URL hängen.</p>'
        . '<textarea readonly spellcheck="false">' . $e(hc_render_json($report)) . '</textarea>';
    $out .= '<p><strong>Nach dem Check: dieses Skript vom Webspace löschen.</strong></p>';

    return $out . '</body></html>';
}

// ---------------------------------------------------------------------------
// Long running probes (each one is its own request)
// ---------------------------------------------------------------------------

/**
 * How long may a request run? mode=local waits inside PHP, mode=remote waits
 * on a delay endpoint you provide yourself (no third party is preset).
 */
function hc_run_longrun(array $params): array
{
    $seconds = max(1, min(600, (int) ($params['seconds'] ?? 60)));
    $mode = ($params['mode'] ?? 'local') === 'remote' ? 'remote' : 'local';
    $limitLifted = function_exists('set_time_limit') && @set_time_limit(0);
    $start = hrtime(true);

    if ($mode === 'remote') {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            return [
                'mode' => 'longrun',
                'sub_mode' => 'remote',
                'requested_seconds' => $seconds,
                'ok' => false,
                'detail' => 'Parameter url fehlt. Eigenen Verzögerungs-Endpunkt angeben, '
                    . 'z. B. url=https://eigener-proxy.example/delay/' . $seconds . '.',
            ];
        }
        $result = hc_http_probe($url, $seconds + 30);

        return [
            'mode' => 'longrun',
            'sub_mode' => 'remote',
            'requested_seconds' => $seconds,
            'elapsed_seconds' => (int) round((hrtime(true) - $start) / 1000000000),
            'set_time_limit_lifted' => $limitLifted,
            'ok' => $result['ok'],
            'http_status' => $result['status'],
            'detail' => $result['detail'],
        ];
    }

    $slept = 0;
    while ($slept < $seconds) {
        sleep(1);
        $slept++;
    }

    return [
        'mode' => 'longrun',
        'sub_mode' => 'local',
        'requested_seconds' => $seconds,
        'elapsed_seconds' => (int) round((hrtime(true) - $start) / 1000000000),
        'set_time_limit_lifted' => $limitLifted,
        'ok' => true,
        'detail' => 'Diese Antwort ist angekommen, der Request lief also mindestens '
            . $seconds . ' s. Nächste Stufe aufrufen, bis nichts mehr ankommt.',
    ];
}

/**
 * Sends mb megabytes spread over the given number of seconds and reports at
 * the end whether everything went out. Plain text, flushed per chunk.
 */
function hc_run_stream(array $params): void
{
    $megabytes = max(1, min(1024, (int) ($params['mb'] ?? 200)));
    $seconds = max(1, min(600, (int) ($params['seconds'] ?? 60)));
    $chunkSize = 64 * 1024;
    $totalChunks = (int) ceil($megabytes * HC_MIB / $chunkSize);
    $delayPerChunk = (int) round($seconds * 1000000 / max(1, $totalChunks));

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    ignore_user_abort(false);

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $start = hrtime(true);
    $filler = str_repeat('x', $chunkSize - 64);
    $sent = 0;
    echo 'Streaming-Test: ' . $megabytes . ' MiB in ' . $totalChunks . ' Blöcken über '
        . $seconds . ' s' . PHP_EOL;
    for ($i = 1; $i <= $totalChunks; $i++) {
        $line = sprintf('%010d %s', $i, $filler);
        echo $line . PHP_EOL;
        $sent += strlen($line) + 1;
        flush();
        if ($delayPerChunk > 0) {
            usleep($delayPerChunk);
        }
    }
    printf(
        'DONE bytes=%d mib=%.1f elapsed=%.1fs%s',
        $sent,
        $sent / HC_MIB,
        (hrtime(true) - $start) / 1000000000,
        PHP_EOL,
    );
    echo 'Wenn diese Zeile im Browser oder in curl ankommt, hat der Host die '
        . 'langsame große Antwort nicht abgeschnitten.' . PHP_EOL;
}

/**
 * SMTP handshake, optional AUTH and optional test mail. Credentials are never
 * echoed back. A mail is only sent when mail_from and mail_to are both given.
 */
function hc_run_smtp(array $params): array
{
    $host = trim((string) ($params['smtp_host'] ?? ''));
    $port = (int) ($params['smtp_port'] ?? 587);
    $secure = (string) ($params['smtp_secure'] ?? 'starttls');
    $user = (string) ($params['smtp_user'] ?? '');
    $pass = (string) ($params['smtp_pass'] ?? '');
    $from = trim((string) ($params['mail_from'] ?? ''));
    $to = trim((string) ($params['mail_to'] ?? ''));
    $steps = [];
    $result = [
        'mode' => 'smtp',
        'host' => $host,
        'port' => $port,
        'secure' => $secure,
        'auth_attempted' => $user !== '',
        'mail_sent' => false,
        'ok' => false,
        'steps' => &$steps,
    ];

    if ($host === '') {
        $steps[] = 'Parameter smtp_host fehlt.';

        return $result;
    }

    $transport = $secure === 'implicit' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    if (!is_resource($socket)) {
        $steps[] = 'Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')';

        return $result;
    }
    stream_set_timeout($socket, 15);

    $read = static function ($socket) use (&$steps): array {
        $lines = [];
        $code = 0;
        while (($line = fgets($socket, 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            $code = (int) substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $steps[] = '< ' . implode(' | ', $lines);

        return ['code' => $code, 'lines' => $lines];
    };
    $write = static function ($socket, string $command, string $shown) use (&$steps): void {
        $steps[] = '> ' . $shown;
        fwrite($socket, $command . "\r\n");
    };

    try {
        $greeting = $read($socket);
        if ($greeting['code'] !== 220) {
            return $result;
        }

        $write($socket, 'EHLO hosting-check', 'EHLO hosting-check');
        $ehlo = $read($socket);
        if ($ehlo['code'] !== 250) {
            return $result;
        }

        if ($secure === 'starttls') {
            $write($socket, 'STARTTLS', 'STARTTLS');
            if ($read($socket)['code'] !== 220) {
                return $result;
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $steps[] = 'STARTTLS-Handshake fehlgeschlagen.';

                return $result;
            }
            $steps[] = 'TLS aktiv.';
            $write($socket, 'EHLO hosting-check', 'EHLO hosting-check');
            if ($read($socket)['code'] !== 250) {
                return $result;
            }
        }

        if ($user !== '') {
            $write($socket, 'AUTH LOGIN', 'AUTH LOGIN');
            if ($read($socket)['code'] !== 334) {
                return $result;
            }
            $write($socket, base64_encode($user), '<Benutzername>');
            if ($read($socket)['code'] !== 334) {
                return $result;
            }
            $write($socket, base64_encode($pass), '<Passwort>');
            if ($read($socket)['code'] !== 235) {
                $steps[] = 'Anmeldung abgelehnt.';

                return $result;
            }
            $steps[] = 'Anmeldung erfolgreich.';
        }

        if ($from !== '' && $to !== '') {
            $write($socket, 'MAIL FROM:<' . $from . '>', 'MAIL FROM:<' . $from . '>');
            if ($read($socket)['code'] !== 250) {
                return $result;
            }
            $write($socket, 'RCPT TO:<' . $to . '>', 'RCPT TO:<' . $to . '>');
            if (!in_array($read($socket)['code'], [250, 251], true)) {
                return $result;
            }
            $write($socket, 'DATA', 'DATA');
            if ($read($socket)['code'] !== 354) {
                return $result;
            }
            $message = 'From: <' . $from . ">\r\n"
                . 'To: <' . $to . ">\r\n"
                . "Subject: Vereinsbelege Hosting-Check\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
                . "Testmail des Hosting-Checks. Keine weiteren Daten enthalten.\r\n.";
            $write($socket, $message, '<Testmail>');
            if ($read($socket)['code'] !== 250) {
                return $result;
            }
            $result['mail_sent'] = true;
        }

        $write($socket, 'QUIT', 'QUIT');
        $read($socket);
        $result['ok'] = true;
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/** Turns --key=value / --key arguments into a parameter map. */
function hc_params_from_argv(array $argv): array
{
    $params = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $argument = substr($argument, 2);
        if (str_contains($argument, '=')) {
            [$key, $value] = explode('=', $argument, 2);
            $params[$key] = $value;

            continue;
        }
        $params[$argument] = '1';
    }

    return $params;
}

/** GET and POST parameters, POST wins so credentials need not be in the URL. */
function hc_params_from_request(array $get, array $post): array
{
    $params = [];
    foreach ([$get, $post] as $source) {
        foreach ($source as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $params[$key] = $value;
            }
        }
    }

    return $params;
}

/** The token the caller presented, from the parameters or from a header. */
function hc_presented_token(array $params, array $server): string
{
    $fromParams = (string) ($params['token'] ?? '');
    if ($fromParams !== '') {
        return $fromParams;
    }

    return (string) ($server['HTTP_X_HOSTING_CHECK_TOKEN'] ?? '');
}

/** The expected token: environment first, then the constant in this file. */
function hc_expected_token(): string
{
    $fromEnv = getenv('VEREINSBELEGE_HC_TOKEN');

    return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : HC_TOKEN;
}

/** Output format: json, html or text. */
function hc_format(array $params, bool $isCli): string
{
    $format = strtolower((string) ($params['format'] ?? ''));
    if (in_array($format, ['json', 'html', 'text'], true)) {
        return $format;
    }

    return $isCli ? 'text' : 'html';
}

/** Which probe was requested: report, longrun, stream or smtp. */
function hc_mode(array $params): string
{
    $mode = strtolower((string) ($params['test'] ?? 'report'));

    return in_array($mode, ['report', 'longrun', 'stream', 'smtp'], true) ? $mode : 'report';
}

/** Runs the tool. Returns the process exit code. */
function hosting_check_main(array $argv, bool $isCli, array $get, array $post, array $server): int
{
    $params = $isCli ? hc_params_from_argv($argv) : hc_params_from_request($get, $post);
    $format = hc_format($params, $isCli);

    if (!$isCli) {
        $expected = hc_expected_token();
        if ($expected === '') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Hosting-Check ist nicht freigeschaltet.' . PHP_EOL
                . 'HC_TOKEN in tools/hosting-check.php setzen oder die Umgebungsvariable '
                . 'VEREINSBELEGE_HC_TOKEN belegen.' . PHP_EOL;

            return 1;
        }
        if (!hc_token_matches($expected, hc_presented_token($params, $server))) {
            usleep(500000);
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Kein Zugriff.' . PHP_EOL;

            return 1;
        }
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }

    $mode = hc_mode($params);

    if ($mode === 'stream') {
        hc_run_stream($params);

        return 0;
    }

    if ($mode === 'longrun' || $mode === 'smtp') {
        $payload = $mode === 'longrun' ? hc_run_longrun($params) : hc_run_smtp($params);
        $payload['parameters'] = hc_redact($params);
        if (!$isCli && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;

        return ($payload['ok'] ?? false) === true ? 0 : 1;
    }

    $report = hc_build_report($params);

    if ($format === 'json') {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo hc_render_json($report);
    } elseif ($format === 'html') {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo hc_render_html($report);
    } else {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo hc_render_text($report);
    }

    return $report['summary']['worst'] === HC_STATUS_FAIL ? 1 : 0;
}

// Tests require this file with HOSTING_CHECK_LOAD_ONLY defined.
if (!defined('HOSTING_CHECK_LOAD_ONLY')) {
    exit(hosting_check_main(
        $argv ?? [],
        PHP_SAPI === 'cli',
        $_GET ?? [],
        $_POST ?? [],
        $_SERVER ?? [],
    ));
}
