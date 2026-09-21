<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The migrator against a real server. Most cases run against the fixture
 * set - which is what makes them a test of the migrator rather than of the
 * schema; the last one runs the application's own migrations the way a
 * fresh installation does.
 */
final class MigrationRunTest extends DatabaseTestCase
{
    private function fixtureDir(): string
    {
        return dirname(__DIR__) . '/fixtures/migrations/valid';
    }

    public function testRunFromZeroAppliesEveryMigrationInOrder(): void
    {
        $migrator = new Migrator($this->pdo(), $this->fixtureDir());

        self::assertSame(0, $migrator->currentVersion());

        $result = $migrator->migrate();

        self::assertSame([1, 2, 10], array_map(
            static fn($m): int => $m->version,
            $result->applied,
        ));
        self::assertSame(0, $result->fromVersion);
        self::assertSame(10, $result->toVersion);

        $tables = $this->pdo()
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['schema_version', 'a', 'b', 'c'] as $expected) {
            self::assertContains($expected, $tables);
        }
    }

    public function testSecondRunIsIdempotent(): void
    {
        new Migrator($this->pdo(), $this->fixtureDir())->migrate();

        $result = new Migrator($this->pdo(), $this->fixtureDir())->migrate();

        self::assertSame([], $result->applied);
        self::assertSame(10, $result->toVersion);
    }

    /**
     * The timestamp comes from PHP, not NOW(): the DB session may run in UTC
     * while the application convention is Europe/Berlin.
     */
    public function testEachAppliedMigrationIsRecordedWithATimestamp(): void
    {
        new Migrator($this->pdo(), $this->fixtureDir())->migrate();

        $rows = $this->pdo()
            ->query('SELECT version, applied_at FROM schema_version ORDER BY version')
            ->fetchAll();

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertNotSame('', (string) $row['applied_at']);
        }
    }

    /**
     * The application's own migrations, as a fresh installation runs them
     * (the installer calls the migrator from 0). Deliberately not pinned to
     * a list of versions - that would turn every new migration into a test
     * change - but a second run has to be a no-op, which is what makes the
     * update step chain repeatable.
     */
    public function testTheApplicationsOwnMigrationsRunFromZero(): void
    {
        $migrator = new Migrator($this->pdo(), $this->migrationsDir());

        $result = $migrator->migrate();

        self::assertNotSame([], $result->applied, 'the schema starts at migration 001');
        self::assertSame(0, $result->fromVersion);
        self::assertSame(
            [],
            new Migrator($this->pdo(), $this->migrationsDir())->migrate()->applied,
            'a repeated run applies nothing',
        );
    }
}
