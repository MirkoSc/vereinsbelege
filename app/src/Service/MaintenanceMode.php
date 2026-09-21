<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The maintenance flag in shared/ (CLAUDE.md section 2). While it exists,
 * the docroot shim answers everything outside /admin with a 503 - which
 * makes it the application's write freeze as well: the public submission
 * page writes through /einreichen, so a set flag stops every anonymous
 * write without any extra bookkeeping in the write path itself.
 *
 * Only the ReleaseSwitcher sets it today, around the renames of an update.
 * It can crash mid-way and leave the flag behind, so the admin area shows a
 * banner with a release button whenever it is set - without that, the only
 * way out would be FTP.
 */
final readonly class MaintenanceMode
{
    public function __construct(private string $flagFile)
    {
    }

    public function enable(string $grund): void
    {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->flagFile, json_encode([
            'seit' => new \DateTimeImmutable()->format('c'),
            'grund' => $grund,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function disable(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    public function isActive(): bool
    {
        return is_file($this->flagFile);
    }

    /**
     * Reason and start time for the admin banner, or null when inactive.
     *
     * Tolerates a flag file that is empty or holds only a bare timestamp: an
     * installation can be updated WHILE the flag is set (that is exactly what
     * the flag is for), so a release may well read a file that a different
     * release wrote. Falling over that would hide the banner and with it the
     * only way to clear the flag without FTP.
     *
     * @return array{seit: string, grund: string}|null
     */
    public function state(): ?array
    {
        if (!$this->isActive()) {
            return null;
        }

        $raw = file_get_contents($this->flagFile);
        if ($raw === false || trim($raw) === '') {
            return ['seit' => '', 'grund' => 'unbekannt'];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [
                'seit' => (string) ($decoded['seit'] ?? ''),
                'grund' => (string) ($decoded['grund'] ?? 'unbekannt'),
            ];
        }

        return ['seit' => trim($raw), 'grund' => 'Update'];
    }
}
