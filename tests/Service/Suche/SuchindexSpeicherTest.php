<?php

declare(strict_types=1);

namespace App\Tests\Service\Suche;

use App\Service\Cron\SuchindexCleanupTask;
use App\Service\Suche\SuchindexSpeicher;
use PHPUnit\Framework\TestCase;

/**
 * The directory of the session indexes (issue #94): parts written whole,
 * ids checked before they become paths, cleanup by file time.
 */
final class SuchindexSpeicherTest extends TestCase
{
    private string $verzeichnis;

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/vb_suchindex_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->verzeichnis)) {
            return;
        }
        foreach (scandir($this->verzeichnis) ?: [] as $id) {
            if ($id === '.' || $id === '..') {
                continue;
            }
            foreach (scandir($this->verzeichnis . '/' . $id) ?: [] as $datei) {
                if ($datei !== '.' && $datei !== '..') {
                    unlink($this->verzeichnis . '/' . $id . '/' . $datei);
                }
            }
            rmdir($this->verzeichnis . '/' . $id);
        }
        rmdir($this->verzeichnis);
    }

    public function testPartsAreWrittenReadAndDeleted(): void
    {
        $speicher = new SuchindexSpeicher($this->verzeichnis);
        $id = SuchindexSpeicher::neueId();

        $speicher->schreibe($id, 1, 'eins');
        $speicher->schreibe($id, 2, 'zwei');
        $speicher->schreibe($id, 2, 'zwei neu');

        self::assertSame('eins', $speicher->lies($id, 1));
        self::assertSame('zwei neu', $speicher->lies($id, 2), 'a repeated step overwrites its part');
        self::assertNull($speicher->lies($id, 3));
        self::assertSame(['1.bin', '2.bin'], array_values(array_diff(scandir($this->verzeichnis . '/' . $id) ?: [], ['.', '..'])), 'no temp file left behind');
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->verzeichnis . '/' . $id . '/1.bin')), -4));

        $speicher->loesche($id);
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/' . $id);
        self::assertNull($speicher->lies($id, 1));
    }

    public function testAnIdIsNeverAPath(): void
    {
        $speicher = new SuchindexSpeicher($this->verzeichnis);

        $this->expectException(\InvalidArgumentException::class);
        $speicher->schreibe('../../etc', 1, 'x');
    }

    public function testDeletingAForeignNameDoesNothing(): void
    {
        mkdir($this->verzeichnis . '/fremd', 0700, true);

        new SuchindexSpeicher($this->verzeichnis)->loesche('../fremd');
        new SuchindexSpeicher($this->verzeichnis)->loesche('fremd');

        self::assertDirectoryExists($this->verzeichnis . '/fremd');
        rmdir($this->verzeichnis . '/fremd');
    }

    public function testTheCronTaskRemovesIndexesUntouchedFor24Hours(): void
    {
        $speicher = new SuchindexSpeicher($this->verzeichnis);
        $alt = SuchindexSpeicher::neueId();
        $neu = SuchindexSpeicher::neueId();
        $speicher->schreibe($alt, 1, 'alt');
        $speicher->schreibe($neu, 1, 'neu');
        $vorlaengst = time() - 25 * 3600;
        touch($this->verzeichnis . '/' . $alt . '/1.bin', $vorlaengst);
        touch($this->verzeichnis . '/' . $alt, $vorlaengst);

        $task = new SuchindexCleanupTask($speicher);

        self::assertSame('suchindex_aufraeumen', $task->name());
        self::assertSame(1, $task->run(new \DateTimeImmutable()));
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/' . $alt);
        self::assertSame('neu', $speicher->lies($neu, 1));
        self::assertSame(0, new SuchindexCleanupTask(new SuchindexSpeicher($this->verzeichnis . '/fehlt'))->run(new \DateTimeImmutable()));
    }
}
