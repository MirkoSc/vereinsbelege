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
 * renders this (milestone M3) is only a view on top, and the values must be
 * readable without an unlocked vault - none of them is business data.
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

    public function __construct(private ?\PDO $pdo = null)
    {
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

        return $results;
    }

    public function status(): CheckStatus
    {
        return CheckStatus::worst(...array_map(
            static fn(CheckResult $r): CheckStatus => $r->status,
            $this->all(),
        ));
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
}
