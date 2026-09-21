<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Config;

/**
 * Opens and hands out the PDO connection, and survives the host's short
 * wait_timeout (issue #98).
 *
 * The target host closes an idle connection after 120 s instead of the usual
 * 28800, while a request may legitimately run far longer: an AI call is
 * allowed ~120 s on its own (docs/spec/06-betrieb.md section 4), and waiting
 * on network I/O does not count against max_execution_time. A job step that
 * waits for the model and then writes the result back finds a dead handle -
 * "MySQL server has gone away".
 *
 * The fix is deliberately a PROBE, not a retry: when the connection has been
 * idle longer than the probe threshold, pdo() runs SELECT 1 first and opens a
 * fresh connection if that fails, so the caller's statement never runs on a
 * handle that was already dead. Retrying a failed statement would mean
 * silently repeating writes whose outcome is unknown, and nothing in this
 * application is idempotent by default.
 *
 * Two things are never reconnected:
 *   - an open transaction: the server dropped it, so re-running the rest of
 *     it against a new connection would commit half a unit of work. It fails
 *     loudly instead.
 *   - a connection that just answered a query (inside the threshold), where
 *     an error means the statement is wrong, not the socket.
 *
 * Long external calls should hand the connection back with release() before
 * they start - see docs/spec/06-betrieb.md section 4. The probe is the net
 * for the paths that forget.
 */
final class ConnectionFactory
{
    /**
     * Below the host's 120 s by a wide margin: the probe costs one round trip
     * on a connection that has been sitting unused anyway, and being wrong in
     * the other direction costs a failed request. Proxies and pool poolers in
     * front of MySQL sometimes cut earlier than wait_timeout says, which is
     * the other reason not to derive this from the server value.
     */
    public const int DEFAULT_IDLE_PROBE_SECONDS = 5;

    private const array OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ];

    private ?\PDO $pdo = null;

    private float $lastUsedAt = 0.0;

    public function __construct(
        private readonly Config $config,
        private readonly int $idleProbeSeconds = self::DEFAULT_IDLE_PROBE_SECONDS,
    ) {
    }

    /**
     * The connection to use for the next statement. Opens one on first call,
     * and replaces a connection the server has closed in the meantime.
     */
    public function pdo(): \PDO
    {
        $pdo = $this->pdo;

        if ($pdo === null) {
            return $this->open();
        }

        if ($this->idleSeconds() >= $this->idleProbeSeconds && !$this->isAlive($pdo)) {
            // An open transaction cannot survive this: the server rolled it
            // back when it dropped the connection, so continuing on a fresh
            // one would commit a fragment. Let the caller fail and decide.
            if ($pdo->inTransaction()) {
                throw new ConnectionLostException(
                    'The database connection was closed while a transaction was open; '
                    . 'the transaction was rolled back by the server.',
                );
            }

            $this->pdo = null;

            return $this->open();
        }

        $this->lastUsedAt = microtime(true);

        return $pdo;
    }

    /**
     * Drops the connection before a long external call (AI request, upload to
     * the worker), so the host's wait_timeout has nothing left to close.
     * The next pdo() opens a new one.
     */
    public function release(): void
    {
        $this->pdo = null;
        $this->lastUsedAt = 0.0;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * One-shot connection for CLI entry points and tests, which live and die
     * inside a single short command and never idle.
     */
    public static function create(Config $config): \PDO
    {
        return new \PDO($config->dsn(), $config->dbUser, $config->dbPassword, self::OPTIONS);
    }

    private function open(): \PDO
    {
        $this->pdo = self::create($this->config);
        $this->lastUsedAt = microtime(true);

        return $this->pdo;
    }

    private function idleSeconds(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }

    /**
     * SELECT 1 is the cheapest statement that actually travels to the server;
     * PDO has no ping and reports a dead socket only when something is sent.
     */
    private function isAlive(\PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
