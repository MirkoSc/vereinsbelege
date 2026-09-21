<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Database\ConnectionFactory;
use App\Database\ConnectionLostException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #98: the host closes an idle connection after wait_timeout = 120 s,
 * while a request may legitimately spend longer than that waiting for an AI
 * call. These tests reproduce it the only way that proves anything - against
 * a real server with SET SESSION wait_timeout = 1 and an actual wait.
 */
final class ConnectionFactoryTest extends DatabaseTestCase
{
    private function factory(int $idleProbeSeconds = 1): ConnectionFactory
    {
        return new ConnectionFactory(Config::fromArray(self::configData()), $idleProbeSeconds);
    }

    public function testReusesTheSameConnectionWhileItIsFresh(): void
    {
        $factory = $this->factory(idleProbeSeconds: 60);

        self::assertFalse($factory->isConnected());
        $first = $factory->pdo();
        self::assertTrue($factory->isConnected());
        self::assertSame($first, $factory->pdo(), 'no reconnect for a connection in active use');
    }

    public function testRebuildsAConnectionTheServerClosedAfterWaitTimeout(): void
    {
        $factory = $this->factory();
        $pdo = $factory->pdo();
        $pdo->exec('SET SESSION wait_timeout = 1');

        // The connection is dead after this, not slow: the server closed the
        // socket. Without the probe the next statement fails with
        // "MySQL server has gone away".
        sleep(3);

        $rebuilt = $factory->pdo();

        self::assertNotSame($pdo, $rebuilt, 'a new connection was opened');
        self::assertSame('1', (string) $rebuilt->query('SELECT 1')->fetchColumn());
    }

    /**
     * The point of probing instead of retrying: the caller's statement must
     * never be the thing that discovers the dead socket, because retrying it
     * would mean repeating a write whose outcome is unknown.
     */
    public function testTheCallerStatementAfterALongWaitJustWorks(): void
    {
        $factory = $this->factory();
        $factory->pdo()->exec('SET SESSION wait_timeout = 1');
        $factory->pdo()->exec('CREATE TABLE probe_target (id INT NOT NULL PRIMARY KEY)');

        sleep(3);

        $insert = $factory->pdo()->prepare('INSERT INTO probe_target (id) VALUES (?)');
        $insert->execute([1]);

        self::assertSame(
            '1',
            (string) $factory->pdo()->query('SELECT COUNT(*) FROM probe_target')->fetchColumn(),
        );
    }

    /**
     * A transaction the server already rolled back must not be continued on a
     * fresh connection - that would commit half a unit of work.
     */
    public function testAnOpenTransactionFailsLoudlyInsteadOfReconnecting(): void
    {
        $factory = $this->factory();
        $pdo = $factory->pdo();
        $pdo->exec('CREATE TABLE tx_target (id INT NOT NULL PRIMARY KEY)');
        $pdo->exec('SET SESSION wait_timeout = 1');

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO tx_target (id) VALUES (1)');

        sleep(3);

        $this->expectException(ConnectionLostException::class);

        $factory->pdo();
    }

    /**
     * The preferred path per docs/spec/06-betrieb.md section 4: a step that is
     * about to wait on something external hands the connection back first, so
     * wait_timeout has nothing left to close.
     */
    public function testReleaseDropsTheConnectionAndTheNextCallOpensAFreshOne(): void
    {
        $factory = $this->factory(idleProbeSeconds: 60);
        $first = $factory->pdo();

        $factory->release();
        self::assertFalse($factory->isConnected());

        $second = $factory->pdo();
        self::assertNotSame($first, $second);
        self::assertSame('1', (string) $second->query('SELECT 1')->fetchColumn());
    }
}
