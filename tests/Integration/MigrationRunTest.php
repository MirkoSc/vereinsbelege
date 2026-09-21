<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * The migrator against a real server. The application's own migrations/ is
 * still empty at this milestone (the schema arrives with M1-5 and M2), so the
 * run uses the fixture set - which is what makes this a test of the migrator
 * rather than of the schema.
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
     * The application's own migrations directory must stay loadable even
     * while it is empty - the installer runs the migrator unconditionally.
     */
    public function testTheApplicationsOwnMigrationsDirectoryRuns(): void
    {
        $migrator = new Migrator($this->pdo(), $this->migrationsDir());

        self::assertSame([], $migrator->migrate()->applied);
        self::assertSame(0, $migrator->currentVersion());
    }
}
