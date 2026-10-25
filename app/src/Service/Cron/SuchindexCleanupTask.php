<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Service\Suche\SuchindexSpeicher;

/**
 * Removes the full-text indexes of sessions that ended without a logout
 * (issue #94, docs/spec/03-erfassung-und-ki.md section 10). They are
 * ciphertext whose key went with the session, so this is housekeeping, not
 * protection: 24 h after the last write - longer than the default absolute
 * session lifetime of 12 h. Should a longer-lived session lose its index
 * this way, it simply builds a new one.
 *
 * File times only - no database, no key (CLAUDE.md section 4).
 */
final readonly class SuchindexCleanupTask implements CronTask
{
    public const int KEEP_HOURS = 24;

    public function __construct(private SuchindexSpeicher $speicher)
    {
    }

    public function name(): string
    {
        return 'suchindex_aufraeumen';
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->speicher->aufraeumen($now->setTimestamp($now->getTimestamp() - self::KEEP_HOURS * 3600));
    }
}
