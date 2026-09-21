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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
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

    public function testTheManifestIsReadAndTolerantOfGarbage(): void
    {
        $service = new RestoreService();

        $mit = $this->zip(['dump.sql' => '', 'manifest.json' => '{"app_version":"1.2.3"}']);
        self::assertSame('1.2.3', $service->manifestAusZip($mit)['app_version']);

        $kaputt = $this->zip(['dump.sql' => '', 'manifest.json' => 'kein json']);
        self::assertSame([], $service->manifestAusZip($kaputt));
    }
}
