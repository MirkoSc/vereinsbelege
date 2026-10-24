<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Backup\MysqlDumper;
use App\Service\Backup\RestoreService;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #208: the database dump runs as a step chain of short requests
 * (MysqlDumper::schritt(), BackupErstellung phase `dump`), so storage
 * backend `db` - the receipts as rows of file_blob_chunk - no longer has to
 * fit its whole dump into one request.
 *
 * What must hold: the parts together are the same dump as one uninterrupted
 * run, a step that broke off and runs again neither doubles nor loses rows,
 * and the result plays back in through the byte-offset restore of #120.
 *
 * Also the hardening of the restore from the review of #206: after a hard
 * break-off the first statement of the next step may have run already.
 */
final class DumpInSchrittenTest extends DatabaseTestCase
{
    /** Chunk rows of the synthetic archive: several portions of 100, across blob ids. */
    private const int BLOBS = 3;
    private const int CHUNKS_JE_BLOB = 130;

    private string $backupDir;
    private string $configFile;
    private string $blobDir;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->backupDir = sys_get_temp_dir() . '/vb_dumpschritte_' . uniqid('', true);
        mkdir($this->backupDir, 0775, true);
        $this->configFile = $this->backupDir . '/config.php';
        file_put_contents($this->configFile, "<?php return [];\n");
        $this->blobDir = sys_get_temp_dir() . '/vb_dumpschritte_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->backupDir, $this->blobDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $datei) {
                @unlink($datei);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /**
     * A synthetic archive of storage backend `db`: chunk rows with the
     * bytes a naive dump trips over, one of them larger than an INSERT's
     * byte budget. Inserted directly, without the vault - the dump only
     * sees rows; foreign keys off because no file_blob rows are needed.
     */
    private function grosserChunkBestand(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $insert = $pdo->prepare('INSERT INTO file_blob_chunk (blob_id, seq, data) VALUES (?, ?, ?)');
        $muster = "\x00\xff\x0a\x0d'\"\\;Beleg\x1a";
        $pdo->beginTransaction();
        for ($blob = 1; $blob <= self::BLOBS; $blob++) {
            for ($seq = 0; $seq < self::CHUNKS_JE_BLOB; $seq++) {
                $daten = str_repeat($muster, 50) . random_bytes(200) . pack('NN', $blob, $seq);
                $insert->bindValue(1, $blob * 1000, \PDO::PARAM_INT);
                $insert->bindValue(2, $seq, \PDO::PARAM_INT);
                $insert->bindValue(3, $daten, \PDO::PARAM_LOB);
                $insert->execute();
            }
        }
        // Larger than MysqlDumper::BYTES_PER_INSERT once written as hex.
        $insert->bindValue(1, 999999, \PDO::PARAM_INT);
        $insert->bindValue(2, 0, \PDO::PARAM_INT);
        $insert->bindValue(3, random_bytes(400 * 1024), \PDO::PARAM_LOB);
        $insert->execute();
        $pdo->commit();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testThePartsTogetherAreTheUninterruptedDumpEvenWithStepsRunTwice(): void
    {
        $this->grosserChunkBestand();
        $dumper = new MysqlDumper($this->pdo());

        $ganz = fopen('php://temp', 'w+b');
        self::assertIsResource($ganz);
        $dumper->dump($ganz);
        rewind($ganz);
        $erwartet = (string) stream_get_contents($ganz);
        fclose($ganz);

        $teile = '';
        $stand = null;
        $schritte = 0;
        do {
            // Every third step "breaks off": its output is thrown away and
            // it runs again from the same state - as the chain does after
            // cutting the work file back to the committed length.
            if ($schritte % 3 === 1) {
                $verworfen = fopen('php://temp', 'w+b');
                self::assertIsResource($verworfen);
                $dumper->schritt($verworfen, $stand, 1, INF);
                fclose($verworfen);
            }
            $strom = fopen('php://temp', 'w+b');
            self::assertIsResource($strom);
            $stand = $dumper->schritt($strom, $stand, 1, INF);
            rewind($strom);
            $teile .= (string) stream_get_contents($strom);
            fclose($strom);
            // Kept between requests as JSON (erstellung.json).
            $stand = json_decode(json_encode($stand, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $schritte++;
            self::assertLessThan(100000, $schritte, 'the chain ends');
        } while ($stand['fertig'] !== true);

        self::assertGreaterThan(self::BLOBS * self::CHUNKS_JE_BLOB / 100, $schritte, 'file_blob_chunk went in portions');
        self::assertSame(strlen($erwartet), strlen($teile));
        self::assertTrue($erwartet === $teile, 'byte for byte the same dump');
        self::assertGreaterThanOrEqual(self::BLOBS * self::CHUNKS_JE_BLOB + 1, $stand['zeilen']);
    }

    /**
     * The admin page's chain with a dump of many steps, some of which die
     * after writing but before their state is kept: the backup restores
     * through the byte-offset chain with file_blob_chunk identical.
     */
    public function testTheChainSurvivesBrokenOffDumpStepsAndRestoresIdentically(): void
    {
        $this->grosserChunkBestand();
        $vorher = $this->pruefsumme('file_blob_chunk');

        $backups = new BackupService($this->pdo(), $this->backupDir, $this->configFile, '9.9.9-test', $this->blobDir);
        // A tiny byte budget: one part (one portion of 100 rows) per step.
        $erstellung = new BackupErstellung($backups, $this->pdo(), $this->backupDir, $this->configFile, null, 1);
        $erstellung->starten(false);

        $status = $this->backupDir . '/erstellung.json';
        $schritte = 0;
        $dumpSchritte = 0;
        do {
            $vorSchritt = (string) file_get_contents($status);
            $stand = $erstellung->schritt();
            if ($stand['phase'] === BackupErstellung::PHASE_DUMP) {
                $dumpSchritte++;
                if ($dumpSchritte % 2 === 0) {
                    // The step wrote into the archive, then the request
                    // died before its state was saved.
                    file_put_contents($status, $vorSchritt);
                }
            }
            $schritte++;
            self::assertLessThan(10000, $schritte, 'the chain ends');
        } while ($stand['phase'] !== BackupErstellung::PHASE_FERTIG);

        self::assertGreaterThan(5, $dumpSchritte, 'the dump took many steps');
        $pfad = $backups->path((string) $stand['name']);
        self::assertNotNull($pfad);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pfad, \ZipArchive::CHECKCONS), 'a consistent ZIP');
        $zip->close();

        $this->tabellenLoeschen();
        $restore = new RestoreService();
        $dumpDatei = $this->backupDir . '/restore_dump.sql';
        $restore->dumpAusZip($pfad, $dumpDatei);
        $offset = 0;
        $runden = 0;
        do {
            $fortschritt = $restore->anwenden(self::neueVerbindung(), $dumpDatei, $offset);
            $offset = $fortschritt['offset'];
            self::assertLessThan(10000, ++$runden);
        } while ($offset < $fortschritt['gesamt']);

        self::assertSame($vorher, $this->pruefsumme('file_blob_chunk'), 'no row doubled or lost');
    }

    public function testTheFirstStatementOfAStepAfterAHardBreakOffMayHaveRunAlready(): void
    {
        $dumpDatei = $this->backupDir . '/flag.sql';
        file_put_contents($dumpDatei, implode("\n", [
            MysqlDumper::SESSION_NAMES . ';',
            MysqlDumper::SESSION_FOREIGN_KEYS_OFF . ';',
            'DROP TABLE IF EXISTS `probe_flag`;',
            'CREATE TABLE `probe_flag` (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;',
            'INSERT INTO `probe_flag` (`id`) VALUES' . "\n(1),\n(2);",
            'INSERT INTO `probe_flag` (`id`) VALUES' . "\n(3);",
            MysqlDumper::SESSION_FOREIGN_KEYS_ON . ';',
            '',
        ]));
        $restore = new RestoreService();
        $grenzen = [0];
        $restore->anwenden(self::neueVerbindung(), $dumpDatei, 0, static function (int $grenze) use (&$grenzen): void {
            $grenzen[] = $grenze;
        });
        // Boundaries: 0 | SET | SET | DROP | CREATE | INSERT 1,2 | INSERT 3 | SET
        $nachDrop = $grenzen[3];
        $nachCreate = $grenzen[4];
        self::assertSame(3, $this->anzahl());

        // The request died right after INSERT (1),(2): it ran, its boundary
        // was not kept, INSERT (3) never came. Without the flag the next
        // step hangs on the duplicate key ...
        $this->pdo()->exec('DELETE FROM probe_flag WHERE id = 3');
        try {
            $restore->anwenden(self::neueVerbindung(), $dumpDatei, $nachCreate);
            self::fail('a duplicate key without the flag must stand');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1] ?? null);
        }
        // ... with it, the statement counts as done and the step moves on.
        $fortschritt = $restore->anwenden(self::neueVerbindung(), $dumpDatei, $nachCreate, null, true);
        self::assertSame($fortschritt['gesamt'], $fortschritt['offset']);
        self::assertSame(3, $this->anzahl());

        // CREATE TABLE ran already (1050): skipped, the rows follow.
        $this->pdo()->exec('DELETE FROM probe_flag');
        $restore->anwenden(self::neueVerbindung(), $dumpDatei, $nachDrop, null, true);
        self::assertSame(3, $this->anzahl());

        // Only the FIRST statement: a duplicate further on is an error.
        try {
            $restore->anwenden(self::neueVerbindung(), $dumpDatei, $nachDrop, null, true);
            self::fail('a duplicate key on a later statement must stand');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1] ?? null);
        }
        $this->pdo()->exec('DROP TABLE probe_flag');
    }

    private function anzahl(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM probe_flag')->fetchColumn();
    }

    private function tabellenLoeschen(): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $table));
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array{int, string} row count and CHECKSUM TABLE of a table
     */
    private function pruefsumme(string $table): array
    {
        $anzahl = (int) $this->pdo()->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        $zeile = $this->pdo()->query(sprintf('CHECKSUM TABLE `%s`', $table))->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($zeile);

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
}
