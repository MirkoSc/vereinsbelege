<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Installer\ServerBackups;
use PHPUnit\Framework\TestCase;

/**
 * Issue #244: the backups an admin put into shared/var/backups/ by FTP -
 * listed and resolved only as plain ZIP names directly in that directory.
 */
final class ServerBackupsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vb_server_backups_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/unter', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->dir . '/unter/*') ?: []), ...(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [])] as $pfad) {
            if (is_link($pfad) || is_file($pfad)) {
                unlink($pfad);
            }
        }
        @rmdir($this->dir . '/unter');
        @rmdir($this->dir);
    }

    public function testListsOnlyPlainZipFilesNewestNameFirst(): void
    {
        file_put_contents($this->dir . '/backup_20260101_120000.zip', 'a');
        file_put_contents($this->dir . '/backup_20261001_120000.zip', 'bb');
        file_put_contents($this->dir . '/Verein Backup.zip', 'x');
        file_put_contents($this->dir . '/.versteckt.zip', 'x');
        file_put_contents($this->dir . '/erstellung_ab.zip.part', 'x');
        file_put_contents($this->dir . '/notiz.txt', 'x');
        file_put_contents($this->dir . '/unter/tief.zip', 'x');
        symlink($this->dir . '/notiz.txt', $this->dir . '/verweis.zip');

        $liste = new ServerBackups($this->dir)->list();

        self::assertSame(
            ['backup_20261001_120000.zip', 'backup_20260101_120000.zip'],
            array_column($liste, 'name'),
        );
        self::assertSame(2, $liste[0]['groesse']);
    }

    public function testPathRefusesEverythingButAPlainNameInTheDirectory(): void
    {
        file_put_contents($this->dir . '/gut.zip', 'x');
        file_put_contents($this->dir . '/unter/tief.zip', 'x');
        symlink($this->dir . '/gut.zip', $this->dir . '/verweis.zip');
        $backups = new ServerBackups($this->dir);

        self::assertSame($this->dir . '/gut.zip', $backups->path('gut.zip'));
        foreach (['', 'fehlt.zip', 'unter/tief.zip', '../gut.zip', 'gut..zip', '/gut.zip', 'gut.zip/', "gut.zip\n", 'verweis.zip', 'unter', 'gut'] as $name) {
            self::assertNull($backups->path($name), var_export($name, true));
        }
    }

    public function testAMissingDirectoryListsNothing(): void
    {
        self::assertSame([], new ServerBackups($this->dir . '/gibt-es-nicht')->list());
    }
}
