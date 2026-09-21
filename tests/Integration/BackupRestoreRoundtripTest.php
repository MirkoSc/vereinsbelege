<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Backup\BackupService;
use App\Service\Migration\Migrator;
use App\Service\Migration\SqlSplitter;
use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory test of issue #6 / docs/spec/06-betrieb.md section 2: create a
 * backup, wipe the database, restore from the dump - the data must be
 * identical.
 */
final class BackupRestoreRoundtripTest extends DatabaseTestCase
{
    private string $backupDir;
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();

        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->backupDir = sys_get_temp_dir() . '/vb_backup_' . uniqid('', true);
        $this->configFile = $this->backupDir . '/config.php';
        mkdir($this->backupDir, 0775, true);
        file_put_contents($this->configFile, "<?php return ['server_key' => 'geheim'];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->pdo(), $this->backupDir, $this->configFile, '9.9.9-test');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumpTable(string $table): array
    {
        return $this->pdo()->query(sprintf('SELECT * FROM `%s` ORDER BY 1', $table))->fetchAll();
    }

    private function wipeDatabase(): void
    {
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $table));
        }
        self::assertSame([], $this->pdo()->query('SHOW TABLES')->fetchAll());
    }

    public function testBackupRestoreRoundtrip(): void
    {
        // Values that break a naive dump: umlauts, both quote kinds, a
        // semicolon (statement splitter), backslashes, a SQL comment marker
        // and a line break inside a string.
        $stmt = $this->pdo()->prepare('INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)');
        foreach ([
            'umlaute' => 'Grün-Weiß „Süd“ Straße',
            'quotes' => "O'Brien \"zitiert\"; DROP TABLE setting; --",
            'backslash' => 'C:\\pfad\\neu \\\' ende',
            'zeilen' => "Zeile 1\nZeile 2\r\n# kein Kommentar",
            'leer' => '',
        ] as $name => $value) {
            $stmt->execute([$name, $value, '2026-01-02 03:04:05']);
        }

        // A second table with NULLs, integers and more rows than fit into
        // one batched INSERT (100).
        $this->pdo()->exec('CREATE TABLE probe (
            id INT NOT NULL PRIMARY KEY,
            zahl BIGINT NULL,
            text_feld VARCHAR(100) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $insert = $this->pdo()->prepare('INSERT INTO probe (id, zahl, text_feld) VALUES (?, ?, ?)');
        for ($i = 1; $i <= 250; $i++) {
            $insert->execute([$i, $i % 3 === 0 ? null : $i * 1000000000, $i % 5 === 0 ? null : 'Zeile ' . $i . ' ä']);
        }

        $before = [];
        foreach (['setting', 'probe', 'schema_version'] as $table) {
            $before[$table] = $this->dumpTable($table);
        }

        $name = $this->service()->create();

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $dump = $zip->getFromName('dump.sql');
        $zip->close();
        self::assertIsString($dump);

        $this->wipeDatabase();

        // Restore exactly like the installer does: split, then exec.
        foreach (SqlSplitter::split($dump) as $statement) {
            $this->pdo()->exec($statement);
        }

        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->dumpTable($table), 'restored table ' . $table . ' must be identical');
        }
    }

    public function testManifestDescribesTheBackup(): void
    {
        $name = $this->service()->create();

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        self::assertSame('9.9.9-test', $manifest['app_version']);
        self::assertSame(1, $manifest['schema_version']);
        self::assertFalse($manifest['config_enthalten']);
    }

    /**
     * The server key must not end up in a file that may be copied around
     * unless the admin asks for it (spec 06 section 2, CLAUDE.md section 4).
     */
    public function testConfigTravelsOnlyOnRequest(): void
    {
        $ohne = $this->service()->create();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $ohne));
        self::assertFalse($zip->getFromName('config.php'), 'default: no config.php in the backup');
        $zip->close();

        // the file name only has second resolution
        rename($this->backupDir . '/' . $ohne, $this->backupDir . '/backup_20200101_000000.zip');

        $mit = $this->service()->create(mitConfig: true);
        self::assertTrue($zip->open($this->backupDir . '/' . $mit));
        self::assertNotFalse($zip->getFromName('config.php'));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertTrue($manifest['config_enthalten']);
        $zip->close();
    }

    public function testNoTemporaryDumpIsLeftBehind(): void
    {
        $this->service()->create();

        self::assertSame([], glob($this->backupDir . '/dump_*') ?: []);
    }

    public function testRotationKeepsNewestTen(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 12; $i++) {
            $name = $service->create();
            // distinct filenames despite the per-second timestamp
            rename(
                $this->backupDir . '/' . $name,
                sprintf('%s/backup_202601%02d_000000.zip', $this->backupDir, $i + 1),
            );
        }
        $service->create();

        $list = $service->list();
        self::assertCount(BackupService::KEEP, $list);
        self::assertStringStartsNotWith('backup_20260101', $list[array_key_last($list)]['name']);
    }

    public function testPathRefusesTraversal(): void
    {
        $service = $this->service();

        self::assertNull($service->path('../../shared/config.php'));
        self::assertNull($service->path('backup_x.zip'));
        self::assertNull($service->path('backup_20990101_000000.zip'), 'valid name but missing file');
    }
}
