<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Free space on the file system that holds `shared/var` - upload chunks,
 * blobs (storage backend "fs"), backups and the log (issue #242).
 *
 * Only a hint, never a guarantee: a host may disable disk_free_space() or
 * fence it off with open_basedir, and on shared hosting it reports the
 * server's disk rather than the account's quota. bytes() then answers null
 * and the caller relies on the byte budget alone (App\Service\Submission\
 * UploadBudget).
 */
final readonly class FreierSpeicher
{
    /**
     * @param (\Closure(string): (float|false))|null $messen stands in for
     *        disk_free_space() in tests
     */
    public function __construct(
        private string $dir,
        private ?\Closure $messen = null,
    ) {
    }

    /** Free bytes, or null when the host does not tell. */
    public function bytes(): ?int
    {
        if ($this->messen === null && !function_exists('disk_free_space')) {
            return null;
        }

        $frei = $this->messen !== null ? ($this->messen)($this->dir) : @disk_free_space($this->dir);

        return is_float($frei) && $frei >= 0 ? (int) min($frei, PHP_INT_MAX) : null;
    }
}
