<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\MysqlDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #244: which `Extra` values of SHOW COLUMNS mark a generated column.
 * The round trip against a real database is in BackupRestoreRoundtripTest.
 */
final class MysqlDumperTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function extras(): array
    {
        return [
            'MariaDB 10.2+/MySQL virtual' => ['VIRTUAL GENERATED', true],
            'MariaDB 10.2+/MySQL stored' => ['STORED GENERATED', true],
            'older MariaDB virtual' => ['VIRTUAL', true],
            'older MariaDB persistent' => ['PERSISTENT', true],
            'lower case' => ['virtual generated', true],
            'plain column' => ['', false],
            'auto increment' => ['auto_increment', false],
            'on update' => ['on update current_timestamp()', false],
            'MySQL 8 expression default' => ['DEFAULT_GENERATED', false],
            'MySQL 8 expression default with on update' => ['DEFAULT_GENERATED on update CURRENT_TIMESTAMP', false],
        ];
    }

    #[DataProvider('extras')]
    public function testRecognisesGeneratedColumns(string $extra, bool $erwartet): void
    {
        self::assertSame($erwartet, MysqlDumper::isGenerated($extra));
    }
}
