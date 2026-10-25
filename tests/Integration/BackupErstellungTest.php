<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Backup\BackupService;
use App\Service\Backup\RestoreService;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\Migration\SqlSplitter;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Tests\Support\DatabaseTestCase;

/**
 * The backup step chain of the admin page (issue #112,
 * docs/spec/06-betrieb.md section 2): built in short steps, the ZIP must be
 * the same backup BackupService::create() makes - restorable by the
 * installer, config.php only on request, nothing half-written ever in the
 * list, and a step that died halfway simply runs again.
 */
final class BackupErstellungTest extends DatabaseTestCase
{
    private string $backupDir;
    private string $configFile;
    private string $blobDir;
    private int $jetzt;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->backupDir = sys_get_temp_dir() . '/vb_erstellung_' . uniqid('', true);
        mkdir($this->backupDir, 0775, true);
        $this->configFile = sys_get_temp_dir() . '/vb_erstellung_config_' . uniqid('', true) . '.php';
        file_put_contents($this->configFile, "<?php return ['server_key' => 'geheim'];\n");
        $this->blobDir = sys_get_temp_dir() . '/vb_erstellung_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
        $this->jetzt = time();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->backupDir);
        self::removeDir($this->blobDir);
        @unlink($this->configFile);
        parent::tearDown();
    }

    private function backups(): BackupService
    {
        return new BackupService($this->pdo(), $this->backupDir, $this->configFile, '9.9.9-test', $this->blobDir);
    }

    private function erstellung(): BackupErstellung
    {
        return new BackupErstellung(
            $this->backups(),
            $this->pdo(),
            $this->backupDir,
            $this->configFile,
            fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . $this->jetzt),
        );
    }

    /**
     * @return array<string, mixed> the final state
     */
    private function bisFertig(BackupErstellung $erstellung, ?int &$schritte = null): array
    {
        $schritte = 0;
        do {
            $stand = $erstellung->schritt();
            $schritte++;
            self::assertLessThan(1000, $schritte, 'the chain must end');
        } while ($stand['phase'] !== BackupErstellung::PHASE_FERTIG);

        return $stand;
    }

    public function testTheChainBuildsARestorableBackupWithBlobs(): void
    {
        $vault = Vault::create();
        $repository = new BlobRepository($this->pdo());
        $blobs = new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
        $inhalt = str_repeat("\x00\xffBeleg;'\"", 30_000);
        $id = $blobs->storeString($inhalt, new BlobMeta('application/pdf', 'Rechnung.pdf'), $vault, BlobStorage::Fs)->id;
        $vorher = $this->pdo()->query('SELECT * FROM file_blob ORDER BY id')->fetchAll();

        $erstellung = $this->erstellung();
        $erstellung->starten(false);
        $stand = $this->bisFertig($erstellung);

        $name = (string) $stand['name'];
        self::assertSame([$name], array_column($this->backups()->list(), 'name'));
        $pfad = $this->backups()->path($name);
        self::assertNotNull($pfad);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pfad, \ZipArchive::CHECKCONS), 'a consistent ZIP');
        $dump = (string) $zip->getFromName('dump.sql');
        self::assertFalse($zip->locateName('config.php'), 'config.php only on request');
        $zip->close();

        $manifest = new RestoreService()->manifestAusZip($pfad);
        self::assertSame('9.9.9-test', $manifest['app_version']);
        self::assertFalse($manifest['config_enthalten']);
        self::assertSame(1, $manifest['blob_dateien']);
        self::assertSame(1, $stand['blob_dateien']);

        // Restore like the installer: dump, then blobs.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $tabelle) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $tabelle));
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (SqlSplitter::split($dump) as $anweisung) {
            $this->pdo()->exec($anweisung);
        }
        self::removeDir($this->blobDir);
        mkdir($this->blobDir, 0775, true);
        $restore = new RestoreService();
        $offset = 0;
        do {
            $fortschritt = $restore->blobsAusZip($pfad, $this->blobDir, $offset);
            self::assertSame(0, $fortschritt['abgelehnt']);
            $offset = $fortschritt['offset'];
        } while ($offset < $fortschritt['gesamt']);

        self::assertSame($vorher, $this->pdo()->query('SELECT * FROM file_blob ORDER BY id')->fetchAll());
        $blob = $blobs->find($id);
        self::assertNotNull($blob);
        self::assertTrue($blobs->verify($blob));
        $zurueck = '';
        foreach ($blobs->openRead($blob, $vault) as $stueck) {
            $zurueck .= $stueck;
        }
        self::assertSame($inhalt, $zurueck);
    }

    public function testConfigTravelsOnlyOnRequest(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten(true);
        $pfad = (string) $this->backups()->path((string) $this->bisFertig($erstellung)['name']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pfad));
        self::assertSame((string) file_get_contents($this->configFile), $zip->getFromName('config.php'));
        $zip->close();
        self::assertTrue(new RestoreService()->manifestAusZip($pfad)['config_enthalten']);
        self::assertTrue($this->backups()->containsConfig(basename($pfad)));
        clearstatcache();
        self::assertSame(0600, fileperms($pfad) & 0777, 'a backup with the server key is readable by its owner only (#78)');
    }

    public function testManyBlobFilesAreAddedInPortions(): void
    {
        $anzahl = BackupErstellung::PORTION_DATEIEN + 5;
        for ($i = 0; $i < $anzahl; $i++) {
            $name = bin2hex(random_bytes(16));
            @mkdir($this->blobDir . '/' . substr($name, 0, 2));
            file_put_contents($this->blobDir . '/' . substr($name, 0, 2) . '/' . $name, random_bytes(10));
        }
        // An unfinished write never travels.
        @mkdir($this->blobDir . '/aa');
        file_put_contents($this->blobDir . '/aa/' . str_repeat('a', 32) . '.part', 'x');

        $erstellung = $this->erstellung();
        $erstellung->starten(false);
        $stand = $this->bisFertig($erstellung, $schritte);

        // dump, two blob portions, the empty check at the end of the
        // second portion is not a separate step, then the conclusion.
        self::assertGreaterThanOrEqual(4, $schritte);
        self::assertSame($anzahl, $stand['blob_dateien']);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $this->backups()->path((string) $stand['name'])));
        self::assertSame($anzahl + 2, $zip->numFiles, 'dump.sql, manifest.json and every blob once');
        $zip->close();
    }

    /**
     * A step that died halfway left bytes behind its last committed length;
     * running it again must still give a valid archive.
     */
    public function testAStepThatDiedHalfwayRunsAgain(): void
    {
        $name = bin2hex(random_bytes(16));
        mkdir($this->blobDir . '/' . substr($name, 0, 2));
        file_put_contents($this->blobDir . '/' . substr($name, 0, 2) . '/' . $name, random_bytes(1000));

        $erstellung = $this->erstellung();
        $stand = $erstellung->starten(false);
        $erstellung->schritt();
        $arbeitsdatei = $this->backupDir . '/erstellung_' . $stand['id'] . '.zip.part';
        self::assertFileExists($arbeitsdatei);
        self::assertSame([], $this->backups()->list(), 'the work file is no backup yet');
        file_put_contents($arbeitsdatei, random_bytes(5000), FILE_APPEND);
        file_put_contents($this->backupDir . '/erstellung_' . $stand['id'] . '.zentral.part', 'kaputt', FILE_APPEND);

        $fertig = $this->bisFertig($erstellung);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $this->backups()->path((string) $fertig['name']), \ZipArchive::CHECKCONS));
        self::assertSame(3, $zip->numFiles);
        $zip->close();
        self::assertSame([], glob($this->backupDir . '/erstellung_*.part'), 'no work file left');
    }

    /**
     * A listed blob file that vanished while the chain ran (deletion,
     * storage switch) stops the chain - never a backup silently missing a
     * file whose row is in the dump.
     */
    public function testAVanishedBlobFileStopsTheChain(): void
    {
        $name = bin2hex(random_bytes(16));
        $pfad = $this->blobDir . '/' . substr($name, 0, 2) . '/' . $name;
        mkdir(dirname($pfad));
        file_put_contents($pfad, random_bytes(100));

        $erstellung = $this->erstellung();
        $erstellung->starten(false);
        $erstellung->schritt();
        unlink($pfad);

        try {
            $erstellung->schritt();
            self::fail('the chain must stop');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('verschwunden', $e->getMessage());
        }
        self::assertSame([], $this->backups()->list(), 'no backup reported as made');
    }

    /**
     * The request died right after moving the archive onto its name: the
     * next step finds the backup instead of reporting a missing work file.
     */
    public function testAHandOverThatDiedAfterTheRenameIsFinishedByTheNextStep(): void
    {
        $erstellung = $this->erstellung();
        $stand = $erstellung->starten(false);
        do {
            $stand = $erstellung->schritt();
        } while ($stand['phase'] !== BackupErstellung::PHASE_ABSCHLUSS);

        // Simulate the crash: the archive lies under its reserved name, the
        // saved state still says "abschluss".
        $gespeichert = $stand;
        unset($gespeichert['gerade_fertig']);
        $fertig = $erstellung->schritt();
        self::assertTrue($fertig['gerade_fertig']);
        $gespeichert['ziel'] = $fertig['name'];
        file_put_contents($this->backupDir . '/erstellung.json', json_encode($gespeichert, JSON_THROW_ON_ERROR));
        touch($this->backupDir . '/erstellung_' . $stand['id'] . '.zentral.part');

        $nochmal = $erstellung->schritt();

        self::assertSame(BackupErstellung::PHASE_FERTIG, $nochmal['phase']);
        self::assertSame($fertig['name'], $nochmal['name']);
        self::assertTrue($nochmal['gerade_fertig']);
        self::assertCount(1, $this->backups()->list());
        self::assertFalse($erstellung->schritt()['gerade_fertig'], 'reported once');
    }

    public function testASecondStartWhileOneRunsIsRefused(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten(false);

        $this->expectException(BackupLaeuftBereits::class);
        $erstellung->starten(true);
    }

    public function testAnAbandonedChainMayBeReplacedAndCancellingCleansUp(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten(false);
        $erstellung->schritt();

        $this->jetzt += BackupErstellung::VERWAIST_NACH_SEKUNDEN + 1;
        self::assertFalse($erstellung->laeuft());
        $neu = $erstellung->starten(true);
        self::assertTrue($neu['mit_config']);
        self::assertSame([], glob($this->backupDir . '/erstellung_*.part'), 'the old work files are gone');

        $erstellung->schritt();
        $erstellung->abbrechen();
        self::assertNull($erstellung->stand());
        self::assertSame([], glob($this->backupDir . '/erstellung_*.part'));
        self::assertSame([], $this->backups()->list());
    }

    public function testAFinishedChainIsNotFinishedTwice(): void
    {
        $erstellung = $this->erstellung();
        $erstellung->starten(false);
        $stand = $this->bisFertig($erstellung);

        self::assertTrue($stand['gerade_fertig']);
        $nochmal = $erstellung->schritt();
        self::assertSame($stand['name'], $nochmal['name']);
        self::assertFalse($nochmal['gerade_fertig'], 'only the finishing step reports it');
        self::assertCount(1, $this->backups()->list());
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
