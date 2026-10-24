<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupCliOptions;
use PHPUnit\Framework\TestCase;

/**
 * Issue #155: `php bin/backup.php --help` created a backup instead of showing
 * the options, and a mistyped option was silently ignored.
 */
final class BackupCliOptionsTest extends TestCase
{
    public function testTheDefaultIsWithoutConfigAndWithBlobs(): void
    {
        $optionen = BackupCliOptions::parse(['bin/backup.php']);

        self::assertFalse($optionen->hilfe);
        self::assertFalse($optionen->mitConfig);
        self::assertTrue($optionen->mitBlobs);
    }

    public function testTheTwoOptionsAreRecognisedInAnyOrder(): void
    {
        $optionen = BackupCliOptions::parse(['bin/backup.php', '--ohne-blobs', '--mit-config']);

        self::assertTrue($optionen->mitConfig);
        self::assertFalse($optionen->mitBlobs);
        self::assertFalse($optionen->hilfe);
    }

    public function testHelpIsAskedForAndNotABackup(): void
    {
        self::assertTrue(BackupCliOptions::parse(['bin/backup.php', '--help'])->hilfe);
        self::assertTrue(BackupCliOptions::parse(['bin/backup.php', '-h'])->hilfe);
        self::assertStringContainsString('--mit-config', BackupCliOptions::USAGE);
        self::assertStringContainsString('--ohne-blobs', BackupCliOptions::USAGE);
        self::assertStringContainsString('nobody can log in', BackupCliOptions::USAGE, 'the help warns about the key-less default');
    }

    public function testAnUnknownOptionIsRefusedAndNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--mit-konfig');

        BackupCliOptions::parse(['bin/backup.php', '--mit-config', '--mit-konfig']);
    }

    public function testAStrayArgumentIsRefusedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('help');

        BackupCliOptions::parse(['bin/backup.php', 'help']);
    }
}
