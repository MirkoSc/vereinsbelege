<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for DB integration tests: connects to the MariaDB from
 * docker-compose (or the CI service container) and creates a dedicated test
 * database.
 *
 * Without a reachable database the tests are skipped, unless
 * TEST_DB_REQUIRED=1 (CI) turns that into a failure - so a broken CI service
 * container cannot quietly reduce the suite to the unit tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?\PDO $sharedPdo = null;
    private static ?string $connectError = null;

    protected function setUp(): void
    {
        $pdo = self::connect();
        if ($pdo === null) {
            if (getenv('TEST_DB_REQUIRED') === '1') {
                self::fail('Test database not available: ' . (self::$connectError ?? 'unknown error'));
            }
            self::markTestSkipped('Test database not available: ' . (self::$connectError ?? 'unknown error'));
        }

        $this->dropAllTables($pdo);
    }

    protected function pdo(): \PDO
    {
        assert(self::$sharedPdo !== null);

        return self::$sharedPdo;
    }

    protected static function dbName(): string
    {
        return getenv('TEST_DB_NAME') ?: 'vereinsbelege_test';
    }

    /**
     * Connection details of the test database, as the application's own
     * Config would provide them.
     *
     * @return array<mixed>
     */
    protected static function configData(): array
    {
        return [
            'debug' => true,
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: 'db',
                'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
                'name' => self::dbName(),
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASSWORD') ?: 'dev-root',
            ],
            'cron_token' => 'test-token',
        ];
    }

    protected function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    private static function connect(): ?\PDO
    {
        if (self::$sharedPdo !== null) {
            return self::$sharedPdo;
        }
        if (self::$connectError !== null) {
            return null;
        }

        $data = self::configData();
        /** @var array{host: string, port: int, user: string, password: string} $db */
        $db = $data['db'];

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
                $db['user'],
                $db['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ],
            );
            $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4', self::dbName()));
            $pdo->exec(sprintf('USE %s', self::dbName()));
        } catch (\PDOException $e) {
            self::$connectError = $e->getMessage();

            return null;
        }

        return self::$sharedPdo = $pdo;
    }

    private function dropAllTables(\PDO $pdo): void
    {
        $tables = $pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', (string) $table));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
