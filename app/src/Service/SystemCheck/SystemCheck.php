<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/**
 * The runtime counterpart to tools/hosting-check.php: that script is run once
 * by hand before the installation, this one answers "is it still true?" on a
 * live system.
 *
 * It exists because both M0 findings are silent. A host that resets
 * zend.exception_ignore_args, or lowers wait_timeout further, changes nothing
 * a user would notice until a trace leaks arguments or a job step dies
 * mid-write. Checking costs two lookups.
 *
 * Deliberately free of Http, Session and Repository: the admin page that
 * renders this (App\Admin\SystemCheckController, issue #107) is only a view
 * on top, and the values must be readable without an unlocked vault - none of
 * them is business data, and none of them is a secret: only ini settings,
 * server variables and yes/no answers ever end up in a CheckResult.
 *
 * The thresholds are the ones of tools/hosting-check.php (docs/spec/
 * 06-betrieb.md section 5): "ok" is what the M0 finding promised, "warn" is
 * degraded but workable, "fail" is below what the application needs.
 */
final readonly class SystemCheck
{
    /**
     * Issue #98: the host runs 120 s. Anything at or below that is a warning
     * rather than a failure - the application copes via
     * Database\ConnectionFactory - but it has to stay visible, because it
     * decides how long an AI call may take (docs/spec/06-betrieb.md section 4).
     */
    public const int WAIT_TIMEOUT_COMFORTABLE = 600;

    private const int MIB = 1024 * 1024;

    /** memory_limit: Argon2id (MODERATE) alone needs 256 MiB. */
    public const int MEMORY_OK = 256 * self::MIB;
    public const int MEMORY_WARN = 128 * self::MIB;

    /** max_execution_time in seconds; 0 means unlimited. */
    public const int EXECUTION_OK = 30;
    public const int EXECUTION_WARN = 20;

    /** upload_max_filesize / post_max_size: one chunk of the chunked upload. */
    public const int UPLOAD_MIN = 2 * self::MIB;

    /** Extensions without which a part of the application cannot work at all. */
    private const array EXTENSIONS = [
        'sodium' => 'Tresor, Verschlüsselung der Dateien und Passwort-Ableitung.',
        'gd' => 'Bildaufbereitung als Fallback, wenn der Browser sie nicht übernimmt.',
        'zip' => 'Export, Archiv-Import und Backup.',
        'curl' => 'Ausgehende Aufrufe: KI-Anbieter, Update-Suche, Mail.',
    ];

    /**
     * @param ?string $varDir absolute path of shared/var; without it the
     *        write check is left out, like the database check without a PDO
     */
    public function __construct(
        private ?\PDO $pdo = null,
        private ?string $varDir = null,
    ) {
    }

    /**
     * @return list<CheckResult>
     */
    public function all(): array
    {
        $results = [$this->exceptionIgnoreArgs()];

        if ($this->pdo !== null) {
            $results[] = $this->waitTimeout($this->pdo);
        }

        $results[] = $this->maxExecutionTime();
        $results[] = $this->memoryLimit();
        $results[] = $this->uploadMaxFilesize();
        $results[] = $this->postMaxSize();
        foreach (array_keys(self::EXTENSIONS) as $extension) {
            $results[] = $this->extension($extension);
        }
        $results[] = $this->argon2id();

        if ($this->varDir !== null) {
            $results[] = $this->varWritable($this->varDir);
        }

        return $results;
    }

    /**
     * Results as one JSON document, for pasting into an issue. Built from
     * CheckResult::toArray() only - there is no other way in, so no secret
     * can ride along.
     *
     * @param list<CheckResult> $results what all() returned
     */
    public static function toJson(array $results): string
    {
        return (string) json_encode([
            'status' => self::worstOf($results)->value,
            'php' => PHP_VERSION,
            'checks' => array_map(static fn(CheckResult $r): array => $r->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param list<CheckResult> $results
     */
    public static function worstOf(array $results): CheckStatus
    {
        return CheckStatus::worst(...array_map(
            static fn(CheckResult $r): CheckStatus => $r->status,
            $results,
        ));
    }

    public function status(): CheckStatus
    {
        return self::worstOf($this->all());
    }

    /**
     * Issue #97. The effective value is what counts, not what php.ini says:
     * bootstrap.php sets it at runtime, so a host shipping 0 still ends up
     * compliant - and a host where the ini_set silently failed shows up here
     * as a failure instead of leaking arguments unnoticed.
     */
    public function exceptionIgnoreArgs(): CheckResult
    {
        $raw = (string) ini_get('zend.exception_ignore_args');
        $on = $raw === '1' || strtolower($raw) === 'on';

        return new CheckResult(
            key: 'zend.exception_ignore_args',
            label: 'Funktionsargumente aus Stacktraces',
            expected: 'On',
            actual: $on ? 'On' : 'Off',
            status: $on ? CheckStatus::Ok : CheckStatus::Fail,
            detail: $on
                ? 'Stacktraces enthalten keine Aufrufargumente.'
                : 'Stacktraces können Passwörter, Schlüssel und Belegdaten enthalten. '
                    . 'Der Bootstrap setzt den Wert – greift er nicht, ist der Hoster gefragt.',
        );
    }

    /**
     * Issue #98. The server value, not a guess: an idle connection dies after
     * it, and a job step that waits on the AI is idle by definition.
     */
    public function waitTimeout(\PDO $pdo): CheckResult
    {
        try {
            $row = $pdo->query("SHOW VARIABLES LIKE 'wait_timeout'")->fetch(\PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            return new CheckResult(
                key: 'wait_timeout',
                label: 'Zeitgrenze für untätige DB-Verbindungen',
                expected: 'ablesbar',
                actual: 'unbekannt',
                status: CheckStatus::Warn,
                // The message of a SHOW VARIABLES failure carries no bound
                // values and no business data - safe to surface.
                detail: 'Abfrage fehlgeschlagen: ' . $e->getMessage(),
            );
        }

        if (!is_array($row) || !isset($row[1])) {
            return new CheckResult(
                key: 'wait_timeout',
                label: 'Zeitgrenze für untätige DB-Verbindungen',
                expected: 'ablesbar',
                actual: 'unbekannt',
                status: CheckStatus::Warn,
                detail: 'Der Server meldet die Variable nicht.',
            );
        }

        $seconds = (int) $row[1];
        $comfortable = $seconds >= self::WAIT_TIMEOUT_COMFORTABLE;

        return new CheckResult(
            key: 'wait_timeout',
            label: 'Zeitgrenze für untätige DB-Verbindungen',
            expected: '≥ ' . self::WAIT_TIMEOUT_COMFORTABLE . ' s',
            actual: $seconds . ' s',
            status: $comfortable ? CheckStatus::Ok : CheckStatus::Warn,
            detail: $comfortable
                ? 'Untätige Verbindungen überleben auch lange externe Aufrufe.'
                : 'Eine untätige Verbindung stirbt nach ' . $seconds . ' s. Lange Aufrufe müssen '
                    . 'die Verbindung vorher freigeben; ConnectionFactory baut sie sonst neu auf.',
        );
    }

    /**
     * "30 s" is what the M0 finding measured. 0 (unlimited) is fine - the
     * real limit then sits in the web server, which the hosting check
     * measures with ?test=longrun and this page cannot.
     */
    public function maxExecutionTime(): CheckResult
    {
        $seconds = (int) ini_get('max_execution_time');

        return new CheckResult(
            key: 'max_execution_time',
            label: 'Laufzeitgrenze je Request',
            expected: '≥ ' . self::EXECUTION_OK . ' s',
            actual: $seconds === 0 ? 'unbegrenzt' : $seconds . ' s',
            status: self::rateMin($seconds === 0 ? -1 : $seconds, self::EXECUTION_OK, self::EXECUTION_WARN),
            detail: 'Kein Request läuft lange (Schrittketten), aber ein einzelner Schritt braucht Luft.',
        );
    }

    public function memoryLimit(): CheckResult
    {
        $bytes = self::parseBytes((string) ini_get('memory_limit'));

        return new CheckResult(
            key: 'memory_limit',
            label: 'Arbeitsspeicher je Request',
            expected: '≥ ' . self::formatBytes(self::MEMORY_OK),
            actual: self::formatBytes($bytes),
            status: self::rateMin($bytes, self::MEMORY_OK, self::MEMORY_WARN),
            detail: 'Argon2id (MODERATE) allein braucht 256 MiB – bei weniger scheitern Anmeldung und Tresor-Entsperren.',
        );
    }

    public function uploadMaxFilesize(): CheckResult
    {
        return $this->uploadLimit('upload_max_filesize', 'Größe einer hochgeladenen Datei');
    }

    public function postMaxSize(): CheckResult
    {
        return $this->uploadLimit('post_max_size', 'Größe eines Requests');
    }

    private function uploadLimit(string $ini, string $label): CheckResult
    {
        $bytes = self::parseBytes((string) ini_get($ini));

        return new CheckResult(
            key: $ini,
            label: $label,
            expected: '≥ ' . self::formatBytes(self::UPLOAD_MIN),
            actual: self::formatBytes($bytes),
            status: self::rateMin($bytes, self::UPLOAD_MIN, self::UPLOAD_MIN),
            detail: 'Dateien gehen in Stücken à 2 MiB hoch (Chunk-Upload) – kleiner darf die Grenze nicht sein.',
        );
    }

    /**
     * A missing extension is a failure: none of the four has a fallback.
     */
    public function extension(string $name): CheckResult
    {
        $loaded = extension_loaded($name);

        return new CheckResult(
            key: 'ext-' . $name,
            label: 'Erweiterung ' . $name,
            expected: 'vorhanden',
            actual: $loaded ? 'vorhanden' : 'fehlt',
            status: $loaded ? CheckStatus::Ok : CheckStatus::Fail,
            detail: self::EXTENSIONS[$name] ?? '',
        );
    }

    /**
     * password_hash() with Argon2id: the PHP build must ship it, a missing
     * constant means the password hashes of the accounts cannot be checked.
     */
    public function argon2id(): CheckResult
    {
        $available = defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true);

        return new CheckResult(
            key: 'password_argon2id',
            label: 'Passwort-Hash Argon2id',
            expected: 'verfügbar',
            actual: $available ? 'verfügbar' : 'fehlt',
            status: $available ? CheckStatus::Ok : CheckStatus::Fail,
            detail: $available
                ? 'password_hash() kann Argon2id.'
                : 'Ohne Argon2id lassen sich Passwörter weder speichern noch prüfen.',
        );
    }

    /**
     * Whether shared/var can really be written to - tried, not just
     * is_writable(), which misleads on some hosts. The probe file carries no
     * content worth reading and is removed again. The path itself stays out
     * of the output: it is a server detail nobody needs in an issue.
     */
    public function varWritable(string $dir): CheckResult
    {
        $label = 'Schreibrechte auf shared/var/';
        $probe = rtrim($dir, '/') . '/.systemcheck-' . bin2hex(random_bytes(6));
        $written = is_dir($dir) && @file_put_contents($probe, 'ok') === 2;
        if (is_file($probe)) {
            @unlink($probe);
        }

        return new CheckResult(
            key: 'var_writable',
            label: $label,
            expected: 'beschreibbar',
            actual: $written ? 'beschreibbar' : (is_dir($dir) ? 'nicht beschreibbar' : 'fehlt'),
            status: $written ? CheckStatus::Ok : CheckStatus::Fail,
            detail: $written
                ? 'Blobs, Backups, Log und temporäre Dateien lassen sich ablegen.'
                : 'Ohne Schreibrechte scheitern Uploads, Backups und das Log.',
        );
    }

    /**
     * Ok at or above $ok, warn at or above $warn, fail below. -1 means
     * "unlimited" and passes; null means unreadable and warns.
     */
    public static function rateMin(?int $value, int $ok, int $warn): CheckStatus
    {
        if ($value === null) {
            return CheckStatus::Warn;
        }
        if ($value < 0 || $value >= $ok) {
            return CheckStatus::Ok;
        }

        return $value >= $warn ? CheckStatus::Warn : CheckStatus::Fail;
    }

    /**
     * "128M", "1G", "-1" to bytes; -1 for unlimited, null when unreadable.
     * Same rules as hc_parse_bytes() in tools/hosting-check.php - that script
     * has to stay dependency-free, so the logic exists twice.
     */
    public static function parseBytes(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(-?\d+)\s*([kmgt]?)b?$/i', $value, $m)) {
            return null;
        }
        $number = (int) $m[1];
        if ($number < 0) {
            return -1;
        }

        return $number * match (strtolower($m[2])) {
            'k' => 1024,
            'm' => self::MIB,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => 1,
        };
    }

    public static function formatBytes(?int $bytes): string
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
            ++$index;
        }

        return ($size === floor($size) ? (string) (int) $size : number_format($size, 1, ',', ''))
            . ' ' . $units[$index];
    }
}
