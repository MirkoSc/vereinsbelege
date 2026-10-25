<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\Arbeitsverzeichnis;
use PHPUnit\Framework\TestCase;

/**
 * Plaintext of a receipt touches files only in memory (docs/spec/07-worker.md
 * section 3): the working directory must be a tmpfs, and every job's files
 * are gone when the job ends - however it ends.
 */
final class ArbeitsverzeichnisTest extends TestCase
{
    private string $basis = '';

    protected function setUp(): void
    {
        $this->basis = sys_get_temp_dir() . '/vb_arbeit_' . bin2hex(random_bytes(6));
        mkdir($this->basis, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basis . '/*/*') ?: [] as $datei) {
            @unlink($datei);
        }
        foreach (glob($this->basis . '/*') ?: [] as $verzeichnis) {
            @rmdir($verzeichnis);
        }
        @rmdir($this->basis);
    }

    public function testADirectoryOnDiskIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tmpfs');

        new Arbeitsverzeichnis($this->basis, "/dev/sda1 / ext4 rw 0 0\n");
    }

    public function testTheLongestMountPointDecides(): void
    {
        $tabelle = "/dev/sda1 / ext4 rw 0 0\ntmpfs /tmp tmpfs rw 0 0\n/dev/sdb1 /tmp/platte ext4 rw 0 0\ntmpfs /mnt/ram\\040disk tmpfs rw 0 0\n";

        self::assertSame('tmpfs', Arbeitsverzeichnis::dateisystem('/tmp/vereinsbelege-worker', $tabelle));
        self::assertSame('ext4', Arbeitsverzeichnis::dateisystem('/tmp/platte/x', $tabelle));
        self::assertSame('ext4', Arbeitsverzeichnis::dateisystem('/tmpfoo', $tabelle), 'a prefix of a name is no mount point');
        self::assertSame('tmpfs', Arbeitsverzeichnis::dateisystem('/mnt/ram disk/job', $tabelle));
        self::assertNull(Arbeitsverzeichnis::dateisystem('/x', ''));
    }

    public function testAJobDirectoryIsPrivateAndRemovedAfterTheJob(): void
    {
        $arbeit = $this->imRam();
        $gesehen = '';

        $arbeit->mitJob(function (string $verzeichnis) use (&$gesehen): void {
            $gesehen = $verzeichnis;
            self::assertSame(0700, fileperms($verzeichnis) & 0777);
            file_put_contents($verzeichnis . '/klartext.pdf', '%PDF');
        });

        self::assertDirectoryDoesNotExist($gesehen);
        self::assertSame(['.', '..'], scandir($this->basis));
    }

    public function testItIsRemovedWhenTheJobFailsAsWell(): void
    {
        $arbeit = $this->imRam();
        $gesehen = '';

        try {
            $arbeit->mitJob(function (string $verzeichnis) use (&$gesehen): never {
                $gesehen = $verzeichnis;
                file_put_contents($verzeichnis . '/seite.jpg', 'x');

                throw new \RuntimeException('Werkzeug kaputt');
            });
        } catch (\RuntimeException) {
        }

        self::assertNotSame('', $gesehen);
        self::assertDirectoryDoesNotExist($gesehen);
    }

    public function testLeftoversOfAKilledWorkerAreRemovedAtStart(): void
    {
        mkdir($this->basis . '/job-alt', 0700);
        file_put_contents($this->basis . '/job-alt/quelle.pdf', '%PDF');

        self::assertSame(1, $this->imRam()->aufraeumen());
        self::assertSame(['.', '..'], scandir($this->basis));
    }

    private function imRam(): Arbeitsverzeichnis
    {
        return new Arbeitsverzeichnis($this->basis, '/dev/sda1 / ext4 rw 0 0' . "\n" . 'tmpfs ' . realpath($this->basis) . " tmpfs rw 0 0\n");
    }
}
