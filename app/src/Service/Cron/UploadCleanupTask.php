<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\Upload\UploadService;

/**
 * Removes upload chunks nobody finished (docs/spec/03-erfassung-und-ki.md
 * section 4: orphaned chunks go after 24 h).
 *
 * A browser that loses its connection halfway leaves a directory full of
 * plaintext chunks behind. Nothing points at it any more - the id only ever
 * existed in that one tab - so only the clock can decide, and 24 h is long
 * enough that a slow upload over a bad mobile connection is never swept away
 * underneath it.
 *
 * No database, no decryption: the task only looks at file timestamps, which
 * is what lets the cron run it at all (CLAUDE.md section 4).
 */
final readonly class UploadCleanupTask implements CronTask
{
    public const int KEEP_HOURS = 24;

    public function __construct(private UploadService $uploads)
    {
    }

    public function name(): string
    {
        return 'uploads_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->uploads->cleanup($now->modify(sprintf('-%d hours', self::KEEP_HOURS)));
    }
}
