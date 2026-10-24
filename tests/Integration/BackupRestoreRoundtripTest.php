<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Service\Backup\BackupService;
use App\Service\Backup\RestoreService;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Migration\SqlStatementStream;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mandatory test of issue #6 and issue #13 / docs/spec/06-betrieb.md
 * section 2: create a backup, wipe the database (and the blob directory),
 * restore - the data must be identical.
 *
 * The blobs are part of it since M2-6, once per storage backend: backend `db`
 * rides in the dump as hexadecimal literals, backend `fs` as files below
 * blobs/. And whatever the backend, what lies in the ZIP must be ciphertext.
 */
final class BackupRestoreRoundtripTest extends DatabaseTestCase
{
    private const string KLARTEXT = 'Rechnung Getraenkemarkt Mueller, 119,00 EUR, Sommerfest';

    private string $backupDir;
    private string $configFile;
    private string $blobDir;
    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->backupDir = sys_get_temp_dir() . '/vb_backup_' . uniqid('', true);
        $this->configFile = $this->backupDir . '/config.php';
        mkdir($this->backupDir, 0775, true);
        file_put_contents($this->configFile, "<?php return ['server_key' => 'geheim'];\n");

        $this->blobDir = sys_get_temp_dir() . '/vb_backup_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->vault = Vault::create();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        self::removeDir($this->blobDir);
        parent::tearDown();
    }

    private function service(?\DateTimeImmutable $festeZeit = null): BackupService
    {
        return new BackupService(
            $this->pdo(),
            $this->backupDir,
            $this->configFile,
            '9.9.9-test',
            $this->blobDir,
            $festeZeit === null ? null : static fn(): \DateTimeImmutable => $festeZeit,
        );
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->blobDir),
        );
    }

    /**
     * @return array<string, array{BlobStorage}>
     */
    public static function backends(): array
    {
        return [
            'db' => [BlobStorage::Db],
            'fs' => [BlobStorage::Fs],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumpTable(string $table, string $order = '1'): array
    {
        return $this->pdo()->query(sprintf('SELECT * FROM `%s` ORDER BY %s', $table, $order))->fetchAll();
    }

    private function wipeDatabase(): void
    {
        // Foreign keys off while dropping: SHOW TABLES is alphabetical, not
        // in dependency order (file_blob before file_blob_chunk).
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $table));
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
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

        $this->wipeDatabase();

        // Restore exactly like the installer does: step by step from byte offsets.
        $this->restoreDump(new RestoreService(), $this->backupDir . '/' . $name);

        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->dumpTable($table), 'restored table ' . $table . ' must be identical');
        }
    }

    /**
     * The acceptance criterion of issue #13: a backup carries the blobs of
     * both backends, and they come back byte for byte - the ciphertext as
     * well as the plaintext behind it.
     */
    #[DataProvider('backends')]
    public function testBackupRestoreRoundtripWithBlobs(BlobStorage $storage): void
    {
        $blobs = $this->blobService();
        // Small and well over the 64 KiB block size, so a `db` blob really is
        // spread over several chunk rows.
        $inhalte = [
            'klein' => self::KLARTEXT,
            'gross' => self::binaryContent(200_000),
        ];

        $ids = [];
        foreach ($inhalte as $label => $inhalt) {
            $ids[$label] = $blobs->storeString(
                $inhalt,
                new BlobMeta('application/pdf', 'Rechnung_Getraenkemarkt_' . $label . '.pdf'),
                $this->vault,
                $storage,
            )->id;
        }

        $vorher = [
            'file_blob' => $this->dumpTable('file_blob'),
            'file_blob_chunk' => $this->dumpTable('file_blob_chunk', '1, 2'),
        ];
        self::assertNotSame([], $vorher['file_blob']);

        $name = $this->service()->create();

        $this->wipeDatabase();
        self::removeDir($this->blobDir);
        mkdir($this->blobDir, 0775, true);

        $this->restore($this->backupDir . '/' . $name);

        // Binary columns first: dek_sealed, header, cipher_sha256 and the
        // chunk data are bytes, and a dump that mangles them is worthless.
        self::assertSame($vorher['file_blob'], $this->dumpTable('file_blob'));
        self::assertSame($vorher['file_blob_chunk'], $this->dumpTable('file_blob_chunk', '1, 2'));

        foreach ($inhalte as $label => $inhalt) {
            $blob = $blobs->find($ids[$label]);
            self::assertNotNull($blob, 'blob row ' . $label . ' after the restore');
            self::assertTrue($blobs->verify($blob), 'ciphertext checksum of ' . $label . ' after the restore');
            self::assertSame($inhalt, self::collect($blobs->openRead($blob, $this->vault)));
            $meta = $blobs->meta($blob, $this->vault);
            self::assertNotNull($meta);
            self::assertSame('Rechnung_Getraenkemarkt_' . $label . '.pdf', $meta->originalName);
        }
    }

    /**
     * "Fachliche Daten liegen im Backup ausschließlich als Chiffrat" - the
     * reason a backup may be downloaded and stored somewhere else at all
     * (spec 06 section 2, CLAUDE.md section 4).
     */
    #[DataProvider('backends')]
    public function testNothingReadableIsInTheBackup(BlobStorage $storage): void
    {
        $this->blobService()->storeString(
            self::KLARTEXT,
            new BlobMeta('text/plain', 'Rechnung_Getraenkemarkt.txt'),
            $this->vault,
            $storage,
        );

        $name = $this->service()->create();

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $inhalt = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $inhalt .= (string) $zip->getFromIndex($i);
        }
        $zip->close();

        self::assertStringNotContainsString('Getraenkemarkt', $inhalt);
        self::assertStringNotContainsString('Sommerfest', $inhalt);
        self::assertStringNotContainsString('119,00', $inhalt);
    }

    /**
     * The update chain backs up without blobs (a single short request), so
     * leaving them out has to be possible and has to say so in the manifest.
     */
    public function testBlobsCanBeLeftOut(): void
    {
        $this->blobService()->storeString(
            self::KLARTEXT,
            new BlobMeta('text/plain'),
            $this->vault,
            BlobStorage::Fs,
        );

        $name = $this->service()->create(mitConfig: false, mitBlobs: false);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        self::assertSame([], self::blobEintraege($zip));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        self::assertSame(0, $manifest['blob_dateien']);
        self::assertSame(0, $manifest['blob_bytes']);
    }

    /**
     * A `.part` file is a write that broke off; no row can ever read it, so
     * it has no business in a backup.
     */
    public function testUnfinishedBlobFilesStayOutOfTheBackup(): void
    {
        mkdir($this->blobDir . '/ab', 0775, true);
        file_put_contents($this->blobDir . '/ab/' . str_repeat('ab', 16), 'Chiffrat');
        file_put_contents($this->blobDir . '/ab/' . str_repeat('ab', 16) . '.1234.part', 'halb');

        $name = $this->service()->create();

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $eintraege = self::blobEintraege($zip);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        self::assertSame(['blobs/ab/' . str_repeat('ab', 16)], $eintraege);
        self::assertSame(1, $manifest['blob_dateien']);
        self::assertSame(8, $manifest['blob_bytes']);
    }

    public function testManifestDescribesTheBackup(): void
    {
        $name = $this->service()->create();

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        self::assertSame('9.9.9-test', $manifest['app_version']);
        // The version the database actually is at - not a literal, which
        // would break with every new migration.
        self::assertSame(
            new Migrator($this->pdo(), $this->migrationsDir())->currentVersion(),
            $manifest['schema_version'],
        );
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
        self::assertNull($service->path('backup_20990101_000000_x.zip'));
        self::assertNull($service->path('backup_20990101_000000_2.zip/../x'));
    }

    /**
     * Issue #155: two backups in the same second used to get the same name,
     * and the second silently replaced the first. Now the second one gets a
     * suffix, both files exist, both are listed (newest first) and both are
     * reachable through path().
     */
    public function testTwoBackupsInTheSameSecondDoNotOverwriteEachOther(): void
    {
        $service = $this->service(new \DateTimeImmutable('2026-10-01 12:00:00'));

        $erstes = $service->create(mitBlobs: false);
        $zweites = $service->create(mitConfig: true);
        $drittes = $service->create();

        self::assertSame('backup_20261001_120000.zip', $erstes);
        self::assertSame('backup_20261001_120000_2.zip', $zweites);
        self::assertSame('backup_20261001_120000_3.zip', $drittes);
        self::assertFileExists($this->backupDir . '/' . $erstes);
        self::assertFileExists($this->backupDir . '/' . $zweites);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $erstes));
        self::assertFalse($zip->getFromName('config.php'), 'the first backup is still the one without config');
        $zip->close();
        self::assertTrue($zip->open($this->backupDir . '/' . $zweites));
        self::assertNotFalse($zip->getFromName('config.php'), 'the second one is the one with it');
        self::assertSame('2026-10-01 12:00:00', json_decode((string) $zip->getFromName('manifest.json'), true)['erstellt_am']);
        $zip->close();

        self::assertSame([$drittes, $zweites, $erstes], array_column($service->list(), 'name'), 'newest first');
        self::assertSame($this->backupDir . '/' . $zweites, $service->path($zweites));

        // From `_10` on, plain string order would put the newest behind
        // `_2` - and rotation would then delete the newest first.
        for ($n = 4; $n <= 11; $n++) {
            $service->create(mitBlobs: false);
        }
        $namen = array_column($service->list(), 'name');
        self::assertSame('backup_20261001_120000_11.zip', $namen[0]);
        self::assertSame('backup_20261001_120000_10.zip', $namen[1]);
        self::assertCount(BackupService::KEEP, $namen, 'rotation kept the newest ten');
        self::assertSame($zweites, $namen[array_key_last($namen)], 'the oldest survivor is _2');
        self::assertFileDoesNotExist($this->backupDir . '/' . $erstes, 'the oldest of the second is the one rotated out');
    }

    /**
     * The suffix is bounded: once a second is "full", create() fails
     * cleanly - nothing of the existing backups is touched and no temporary
     * file stays behind.
     */
    public function testTooManyBackupsInOneSecondFailCleanly(): void
    {
        $service = $this->service(new \DateTimeImmutable('2026-10-01 12:00:00'));
        touch($this->backupDir . '/backup_20261001_120000.zip');
        for ($n = 2; $n <= BackupService::MAX_PER_SECOND; $n++) {
            touch(sprintf('%s/backup_20261001_120000_%d.zip', $this->backupDir, $n));
        }
        $vorher = glob($this->backupDir . '/backup_*') ?: [];

        try {
            $service->create();
            self::fail('the hundred-and-first backup in one second has to fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('derselben Sekunde', $e->getMessage());
        }

        self::assertSame($vorher, glob($this->backupDir . '/backup_*') ?: [], 'nothing overwritten, nothing added');
        self::assertSame([], glob($this->backupDir . '/dump_*') ?: []);
    }

    /** Restores a backup ZIP the way the installer does: dump, then blobs. */
    private function restore(string $zipPfad): void
    {
        $restore = new RestoreService();
        $this->restoreDump($restore, $zipPfad);

        $offset = 0;
        do {
            $fortschritt = $restore->blobsAusZip($zipPfad, $this->blobDir, $offset);
            self::assertSame(0, $fortschritt['abgelehnt']);
            $offset = $fortschritt['offset'];
        } while ($offset < $fortschritt['gesamt']);
    }

    /**
     * Plays dump.sql in like the installer's step chain: one anwenden() per
     * request, each resuming at the byte offset the one before returned.
     *
     * @return list<array{offset: int, gesamt: int, anweisungen: int, gelesen: int}> one entry per step
     */
    private function restoreDump(RestoreService $restore, string $zipPfad): array
    {
        $dumpDatei = $this->backupDir . '/restore_dump.sql';
        $restore->dumpAusZip($zipPfad, $dumpDatei);

        $schritte = [];
        $offset = 0;
        do {
            // A connection of its own per step, like one request each: the
            // dump's session settings must not leak from the first step.
            $fortschritt = $restore->anwenden(self::neueVerbindung(), $dumpDatei, $offset);
            self::assertGreaterThan($offset, $fortschritt['offset'], 'every step moves on');
            $offset = $fortschritt['offset'];
            $schritte[] = $fortschritt;
            self::assertLessThan(10000, count($schritte), 'the chain ends');
        } while ($offset < $fortschritt['gesamt']);
        unlink($dumpDatei);

        return $schritte;
    }

    /**
     * @return array{int, string} row count and CHECKSUM TABLE of a table
     */
    private function pruefsumme(string $table): array
    {
        $anzahl = (int) $this->pdo()->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        $zeile = $this->pdo()->query(sprintf('CHECKSUM TABLE `%s`', $table))->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($zeile);
        self::assertNotNull($zeile[1], 'checksum available');

        return [$anzahl, (string) $zeile[1]];
    }

    private static function neueVerbindung(): \PDO
    {
        /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
        $db = self::configData()['db'];

        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            $db['user'],
            $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Issue #120: a dump with many more statements than one step runs - and
     * the values that trip a splitter, some longer than the read buffer -
     * comes back identical through the byte-offset chain, and the chain
     * reads the file once instead of once per step.
     */
    public function testALargeDumpRestoresInStepsFromByteOffsets(): void
    {
        $this->pdo()->exec('CREATE TABLE probe_gross (
            id INT NOT NULL PRIMARY KEY,
            text_feld MEDIUMTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $insert = $this->pdo()->prepare('INSERT INTO probe_gross (id, text_feld) VALUES (?, ?)');
        $knifflig = [
            "Semikolon; im String; -- kein Kommentar",
            "O'Brien \"zitiert\" /* kein Block */ # keine Zeile",
            'Backslash am Ende \\',
            "Zeile 1\nZeile 2; \\' noch im String",
        ];
        $this->pdo()->beginTransaction();
        for ($i = 1; $i <= 30000; $i++) {
            $insert->execute([$i, $i % 7 === 0 ? null : $knifflig[$i % 4] . ' ' . $i]);
        }
        $this->pdo()->commit();
        // Longer than the 1 MiB read buffer, with a semicolon near both ends.
        $insert->execute([30001, ';' . str_repeat("ab'c;d\\", 200000) . ';']);

        // Compared by checksum, not row by row: 30 000 rows fetched twice
        // would raise the memory the whole test run holds on to.
        $vorher = $this->pruefsumme('probe_gross');
        $name = $this->service()->create();
        $this->wipeDatabase();

        $schritte = $this->restoreDump(new RestoreService(), $this->backupDir . '/' . $name);

        self::assertSame($vorher, $this->pruefsumme('probe_gross'), 'restored byte for byte');
        self::assertGreaterThan(1, count($schritte), 'more than one step');
        $anweisungen = array_sum(array_column($schritte, 'anweisungen'));
        self::assertGreaterThan(RestoreService::STATEMENTS_PER_STEP, $anweisungen);

        // No step starts over at byte 0: together they read the file about
        // once - each at most one read buffer beyond its own share.
        $gesamt = $schritte[0]['gesamt'];
        self::assertLessThanOrEqual(
            $gesamt + count($schritte) * SqlStatementStream::READ_BYTES,
            array_sum(array_column($schritte, 'gelesen')),
        );
    }

    /**
     * @return list<string>
     */
    private static function blobEintraege(\ZipArchive $zip): array
    {
        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, BackupService::BLOB_PREFIX)) {
                $namen[] = $name;
            }
        }

        return $namen;
    }

    /**
     * @param iterable<string> $chunks
     */
    private static function collect(iterable $chunks): string
    {
        $result = '';
        foreach ($chunks as $chunk) {
            $result .= $chunk;
        }

        return $result;
    }

    /** Binary content with the bytes a naive dump trips over. */
    private static function binaryContent(int $size): string
    {
        $pattern = "\x00\xff\x0a\x0d'\"\\;Beleg\x1a";

        return substr(str_repeat($pattern, (int) ($size / strlen($pattern)) + 1), 0, $size);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
