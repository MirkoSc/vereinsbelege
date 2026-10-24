<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Installer\ConfigWriter;
use App\Service\Backup\MysqlDumper;
use App\Service\Backup\RestoreService;
use App\Service\Migration\SqlSplitter;
use App\Service\Migration\SqlStatementStream;
use App\Tests\Support\RecordingPdo;
use PHPUnit\Framework\TestCase;

final class RestoreServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vb_restore_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
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

    /**
     * @param array<string, string> $files name => content
     */
    private function zip(array $files): string
    {
        $path = $this->dir . '/' . uniqid('b', true) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function testDumpIsCopiedOutOfTheZip(): void
    {
        $zip = $this->zip(['dump.sql' => "SELECT 1;\n"]);
        $ziel = $this->dir . '/out.sql';

        new RestoreService()->dumpAusZip($zip, $ziel);

        self::assertSame("SELECT 1;\n", file_get_contents($ziel));
    }

    public function testAZipWithoutDumpIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dump.sql fehlt');

        new RestoreService()->dumpAusZip($this->zip(['etwas.txt' => 'x']), $this->dir . '/out.sql');
    }

    public function testSomethingThatIsNoZipIsRefused(): void
    {
        $datei = $this->dir . '/kein.zip';
        file_put_contents($datei, 'das ist kein ZIP');

        $this->expectException(\RuntimeException::class);

        new RestoreService()->dumpAusZip($datei, $this->dir . '/out.sql');
    }

    public function testTheServerKeyIsReadFromAConfigWrittenByTheInstaller(): void
    {
        $config = $this->dir . '/config.php';
        $schluessel = base64_encode(random_bytes(ConfigWriter::SERVER_KEY_BYTES));
        ConfigWriter::write($config, ['host' => 'h', 'port' => 3306, 'name' => 'n', 'user' => 'u', 'password' => 'p'], $schluessel);

        $zip = $this->zip(['dump.sql' => '', 'config.php' => (string) file_get_contents($config)]);

        self::assertSame($schluessel, new RestoreService()->serverSchluesselAusZip($zip));
    }

    /**
     * The ZIP is an upload. If its config.php were included, anyone who can
     * open /install before the installation is finished could run code.
     */
    public function testAnUploadedConfigIsNeverExecuted(): void
    {
        $marker = $this->dir . '/ausgefuehrt.txt';
        $boesartig = "<?php file_put_contents('" . addslashes($marker) . "', 'ja'); return ['server_key' => 'x'];\n";
        $zip = $this->zip(['dump.sql' => '', 'config.php' => $boesartig]);

        new RestoreService()->serverSchluesselAusZip($zip);

        self::assertFileDoesNotExist($marker);
    }

    public function testInvalidOrMissingKeysGiveNull(): void
    {
        $service = new RestoreService();

        self::assertNull($service->serverSchluesselAusZip($this->zip(['dump.sql' => ''])), 'no config.php');
        self::assertNull(
            $service->serverSchluesselAusZip($this->zip(['dump.sql' => '', 'config.php' => "<?php return [];\n"])),
            'no server_key entry',
        );
        self::assertNull(
            $service->serverSchluesselAusZip($this->zip([
                'dump.sql' => '',
                'config.php' => "<?php return ['server_key' => '" . base64_encode('zu kurz') . "'];\n",
            ])),
            'wrong length',
        );
    }

    public function testBlobsAreWrittenUnderTheirOwnNames(): void
    {
        $name = str_repeat('ab', 16);
        $zip = $this->zip([
            'dump.sql' => '',
            'blobs/' . substr($name, 0, 2) . '/' . $name => 'Chiffrat',
        ]);
        $ziel = $this->dir . '/blobs';

        $fortschritt = new RestoreService()->blobsAusZip($zip, $ziel, 0);

        self::assertSame(['offset' => 1, 'gesamt' => 1, 'abgelehnt' => 0], $fortschritt);
        self::assertSame('Chiffrat', file_get_contents($ziel . '/ab/' . $name));
    }

    /**
     * The ZIP is an upload: nothing is unpacked under a name that came out of
     * the archive. Only blobs/<2 chars>/<32 hex> is written, and only where
     * the subdirectory matches the name - everything else is counted and
     * dropped.
     */
    public function testOnlyProperBlobNamesAreWritten(): void
    {
        $gut = str_repeat('ab', 16);
        $zip = $this->zip([
            'dump.sql' => '',
            'blobs/../../entkommen.txt' => 'boese',
            'blobs//entkommen.txt' => 'boese',
            'blobs/ab/KEIN_HEX' => 'boese',
            'blobs/zz/' . $gut => 'boese',
            'blobs/ab/' . str_repeat('cd', 16) => 'boese',
            'blobs/' . substr($gut, 0, 2) . '/' . $gut => 'Chiffrat',
        ]);
        $ziel = $this->dir . '/blobs';

        $fortschritt = new RestoreService()->blobsAusZip($zip, $ziel, 0);

        self::assertSame(6, $fortschritt['gesamt']);
        self::assertSame(6, $fortschritt['offset'], 'a rejected entry still advances the chain');
        self::assertSame(5, $fortschritt['abgelehnt']);

        self::assertSame(['ab'], array_values(array_diff((array) scandir($ziel), ['.', '..'])));
        self::assertSame([$gut], array_values(array_diff((array) scandir($ziel . '/ab'), ['.', '..'])));
        self::assertFileDoesNotExist($this->dir . '/entkommen.txt');
        self::assertFileDoesNotExist(dirname($this->dir) . '/entkommen.txt');
    }

    /**
     * No single request may run long (CLAUDE.md section 1): the byte budget
     * stops a step even when the file count would still allow more.
     */
    public function testBlobsComeInPortions(): void
    {
        $groessen = [RestoreService::BLOB_BYTES_PER_STEP, 32];
        $namen = [str_repeat('1a', 16), str_repeat('2b', 16)];
        $zip = $this->zip([
            'dump.sql' => '',
            'blobs/' . substr($namen[0], 0, 2) . '/' . $namen[0] => str_repeat('x', $groessen[0]),
            'blobs/' . substr($namen[1], 0, 2) . '/' . $namen[1] => str_repeat('y', $groessen[1]),
        ]);
        $ziel = $this->dir . '/blobs';
        $service = new RestoreService();

        $erster = $service->blobsAusZip($zip, $ziel, 0);
        self::assertSame(1, $erster['offset'], 'the byte budget ends the step after the first file');
        self::assertSame(2, $erster['gesamt']);

        $zweiter = $service->blobsAusZip($zip, $ziel, $erster['offset']);
        self::assertSame(2, $zweiter['offset']);
        self::assertSame(2, $zweiter['gesamt'], 'the total does not move between requests');

        foreach ($namen as $i => $name) {
            self::assertSame($groessen[$i], filesize($ziel . '/' . substr($name, 0, 2) . '/' . $name));
        }
    }

    public function testABackupWithoutBlobsNeedsNoBlobPhase(): void
    {
        $zip = $this->zip(['dump.sql' => '', 'manifest.json' => '{}']);
        $service = new RestoreService();

        self::assertSame(0, $service->blobAnzahlImZip($zip));
        self::assertSame(
            ['offset' => 0, 'gesamt' => 0, 'abgelehnt' => 0],
            $service->blobsAusZip($zip, $this->dir . '/blobs', 0),
        );
        self::assertDirectoryDoesNotExist($this->dir . '/blobs');
    }

    public function testTheManifestIsReadAndTolerantOfGarbage(): void
    {
        $service = new RestoreService();

        $mit = $this->zip(['dump.sql' => '', 'manifest.json' => '{"app_version":"1.2.3"}']);
        self::assertSame('1.2.3', $service->manifestAusZip($mit)['app_version']);

        $kaputt = $this->zip(['dump.sql' => '', 'manifest.json' => 'kein json']);
        self::assertSame([], $service->manifestAusZip($kaputt));
    }

    /**
     * @param list<string> $ausgefuehrt
     */
    private function pdo(array &$ausgefuehrt): \PDO
    {
        return RecordingPdo::create($ausgefuehrt);
    }

    /**
     * The log without the session settings, which every resumed step
     * repeats - for comparing the order of the dump's own statements.
     *
     * @param list<string> $log
     *
     * @return list<string>
     */
    private static function ohneSitzung(array $log): array
    {
        $sitzung = [
            MysqlDumper::SESSION_NAMES,
            MysqlDumper::SESSION_FOREIGN_KEYS_OFF,
            MysqlDumper::SESSION_FOREIGN_KEYS_ON,
        ];

        return array_values(array_filter($log, static fn(string $s): bool => !in_array($s, $sitzung, true)));
    }

    /**
     * A synthetic dump in the dumper's shape, with the values that trip a
     * splitter.
     */
    private function dump(int $inserts): string
    {
        $sql = "-- Vereinsbelege DB dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        $sql .= "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int, `v` text, `b` blob);\n";
        for ($i = 0; $i < $inserts; $i++) {
            $sql .= sprintf(
                "INSERT INTO `t` (`id`, `v`, `b`) VALUES (%d, 'a;b -- c /* d */ # e \\' f\\\\', 0x%s), (%d, NULL, '');\n",
                $i,
                bin2hex(chr($i % 256)),
                -$i,
            );
        }

        return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /**
     * Issue #120: the chain runs every statement exactly once, in order, each
     * step resuming at the byte offset the step before returned - not at
     * byte 0.
     */
    public function testTheDumpRunsInStepsFromByteOffsets(): void
    {
        $sql = $this->dump(1000);
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, $sql);
        $ausgefuehrt = [];
        $pdo = $this->pdo($ausgefuehrt);
        $service = new RestoreService();

        $offset = 0;
        $schritte = [];
        do {
            $fortschritt = $service->anwenden($pdo, $datei, $offset);
            self::assertGreaterThan($offset, $fortschritt['offset']);
            self::assertSame(strlen($sql), $fortschritt['gesamt'], 'the total is the size of the dump');
            self::assertLessThanOrEqual(RestoreService::STATEMENTS_PER_STEP, $fortschritt['anweisungen']);
            // Step n reads from its own offset on, not from byte 0: its
            // share of the file plus at most one read buffer ahead.
            self::assertLessThanOrEqual(
                $fortschritt['offset'] - $offset + SqlStatementStream::READ_BYTES,
                $fortschritt['gelesen'],
            );
            self::assertLessThanOrEqual($fortschritt['gesamt'] - $offset, $fortschritt['gelesen']);
            $schritte[] = $fortschritt;
            $offset = $fortschritt['offset'];
        } while ($offset < $fortschritt['gesamt']);

        self::assertSame(self::ohneSitzung(SqlSplitter::split($sql)), self::ohneSitzung($ausgefuehrt));
        self::assertCount(6, $schritte, '1005 statements in blocks of 200');
        self::assertSame(1005, array_sum(array_column($schritte, 'anweisungen')));
    }

    /**
     * A request that breaks off mid-block - here: after any statement -
     * resumes behind the last statement it reported, so nothing runs twice
     * and nothing is left out.
     */
    public function testABrokenOffStepResumesBehindTheLastStatement(): void
    {
        $sql = $this->dump(30);
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, $sql);
        $erwartet = SqlSplitter::split($sql);
        $service = new RestoreService();

        for ($abbruchNach = 1; $abbruchNach <= count($erwartet); $abbruchNach++) {
            $ausgefuehrt = [];
            $pdo = $this->pdo($ausgefuehrt);
            $gemerkt = 0;
            $gezaehlt = 0;
            try {
                $service->anwenden($pdo, $datei, 0, static function (int $grenze) use (&$gemerkt, &$gezaehlt, $abbruchNach): void {
                    $gemerkt = $grenze;
                    if (++$gezaehlt === $abbruchNach) {
                        throw new \RuntimeException('Abbruch');
                    }
                });
            } catch (\RuntimeException $e) {
                self::assertSame('Abbruch', $e->getMessage());
            }
            self::assertCount($abbruchNach, $ausgefuehrt, 'the first step starts at 0, without extra settings');

            $offset = $gemerkt;
            while ($offset < strlen($sql)) {
                $offset = $service->anwenden($pdo, $datei, $offset)['offset'];
            }

            self::assertSame(self::ohneSitzung($erwartet), self::ohneSitzung($ausgefuehrt), 'broken off after statement ' . $abbruchNach);
        }
    }

    public function testLargeStatementsEndAStepByTheByteBudget(): void
    {
        // 20 INSERTs of about 1 MiB each: the byte budget, not the count of
        // 200, ends a step.
        $zeile = "INSERT INTO c VALUES (0x" . str_repeat('ab', 512 * 1024) . ");\n";
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, str_repeat($zeile, 20));
        $ausgefuehrt = [];

        $erster = new RestoreService()->anwenden($this->pdo($ausgefuehrt), $datei, 0);

        $proSchritt = intdiv(RestoreService::DUMP_BYTES_PER_STEP, strlen($zeile)) + 1;
        self::assertSame($proSchritt, $erster['anweisungen']);
        self::assertSame($proSchritt * strlen($zeile) - 1, $erster['offset'], 'just behind the semicolon');
    }

    public function testAStatementLargerThanTheBudgetStillRuns(): void
    {
        $zeile = "INSERT INTO c VALUES (0x" . str_repeat('ab', RestoreService::DUMP_BYTES_PER_STEP / 2 + 16) . ");\n";
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, $zeile . 'SELECT 1;');
        $ausgefuehrt = [];

        $erster = new RestoreService()->anwenden($this->pdo($ausgefuehrt), $datei, 0);

        self::assertSame(1, $erster['anweisungen'], 'the chain moves on');
        self::assertSame(strlen($zeile) - 1, $erster['offset']);
    }

    public function testTrailingCommentsFinishTheChain(): void
    {
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, "SELECT 1;\n-- Ende\n");
        $ausgefuehrt = [];

        $fortschritt = new RestoreService()->anwenden($this->pdo($ausgefuehrt), $datei, 0);

        self::assertSame(['SELECT 1'], $ausgefuehrt);
        self::assertSame($fortschritt['gesamt'], $fortschritt['offset']);
    }

    /**
     * A restore session of a version before issue #120 counts statements:
     * the count turns into the byte offset behind exactly that statement.
     */
    public function testAStatementCountTurnsIntoTheMatchingByteOffset(): void
    {
        $sql = $this->dump(500);
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, $sql);
        $alle = SqlSplitter::split($sql);
        $service = new RestoreService();

        foreach ([0, 1, 200, 400, count($alle) - 1, count($alle), count($alle) + 5] as $bisher) {
            $umgerechnet = $service->byteOffsetNachAnweisungen($datei, $bisher);
            self::assertSame(min($bisher, count($alle)), $umgerechnet['anweisungen']);
            $offset = $umgerechnet['offset'];
            $rest = [];
            $pdo = $this->pdo($rest);
            while ($offset < strlen($sql)) {
                $offset = $service->anwenden($pdo, $datei, $offset)['offset'];
            }
            self::assertSame(self::ohneSitzung(array_slice($alle, $bisher)), self::ohneSitzung($rest), 'after ' . $bisher . ' statements');
        }
    }

    /**
     * Each step is a new request with a new connection: a resumed step
     * repeats the dump's session settings first, and the step that reaches
     * the end turns foreign key checks back on for the migrations after it.
     */
    public function testAResumedStepRepeatsTheSessionSettingsOfTheDump(): void
    {
        $datei = $this->dir . '/dump.sql';
        file_put_contents($datei, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSELECT 1;\nSELECT 2;\nSET FOREIGN_KEY_CHECKS = 1;\n");
        $service = new RestoreService();
        $nachEins = strpos((string) file_get_contents($datei), 'SELECT 1;') + strlen('SELECT 1;');

        $log = [];
        $service->anwenden($this->pdo($log), $datei, $nachEins);
        self::assertSame([
            'SET NAMES utf8mb4',
            'SET FOREIGN_KEY_CHECKS = 0',
            'SELECT 2',
            'SET FOREIGN_KEY_CHECKS = 1',
            'SET FOREIGN_KEY_CHECKS = 1',
        ], $log);

        // Only the trailing newline left: still back to checks on.
        $log = [];
        $groesse = (int) filesize($datei);
        $fortschritt = $service->anwenden($this->pdo($log), $datei, $groesse - 1);
        self::assertSame($groesse, $fortschritt['offset']);
        self::assertSame(['SET NAMES utf8mb4', 'SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'], $log);
    }
}
