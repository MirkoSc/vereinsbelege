<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A PDO that runs nothing and records each statement given to exec(). It is
 * made without its constructor, so no database driver is needed. Unlike a
 * PHPUnit stub it keeps no invocation objects, which would hold every
 * argument - MiB-sized dump statements included - until the run ends.
 * Statements above 4 KiB are recorded as a fingerprint for the same reason.
 */
final class RecordingPdo extends \PDO
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @param list<string> $log filled with every statement run
     */
    public static function create(array &$log): self
    {
        $pdo = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $pdo->log = &$log;

        return $pdo;
    }

    public function exec(string $statement): int|false
    {
        $this->log[] = strlen($statement) > 4096 ? 'sha1:' . sha1($statement) : $statement;

        return 0;
    }
}
