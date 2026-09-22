<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Installer\ConfigWriter;
use App\Service\Backup\RestoreService;
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
}
