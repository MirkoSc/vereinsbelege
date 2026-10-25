<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * Backup ZIPs the admin put into shared/var/backups/ by FTP, for the
 * installer to restore from (issue #244, E-66, docs/spec/06-betrieb.md
 * section 2 "Einspielen").
 *
 * The way for backups larger than upload_max_filesize/post_max_size: the
 * hoster limits what one upload may carry, FTP has no such limit, and the
 * restore chain itself already works in short requests. The installer never
 * copies or deletes such a file - it is the admin's, and afterwards it is
 * simply one of the backups in the list of /admin/backups (if it carries a
 * name of that pattern).
 *
 * Only plain file names directly in that directory are accepted: no path,
 * no traversal, no hidden file, no symlink, nothing but `.zip`. The
 * installer is reachable without a login while config.php is missing, so
 * the name in the form is a client's claim like any other.
 */
final readonly class ServerBackups
{
    private const string MUSTER = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.zip$/D';

    public function __construct(private string $backupDir)
    {
    }

    /**
     * @return list<array{name: string, groesse: int, geaendert_am: string}> newest name first
     */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->backupDir . '/*.zip') ?: [] as $pfad) {
            $name = basename($pfad);
            if ($this->path($name) === null) {
                continue;
            }
            $backups[] = [
                'name' => $name,
                'groesse' => (int) filesize($pfad),
                'geaendert_am' => date('Y-m-d H:i', (int) filemtime($pfad)),
            ];
        }
        usort($backups, static fn(array $a, array $b): int => strnatcmp($b['name'], $a['name']));

        return $backups;
    }

    /** The validated path of `$name` in the backup directory, or null. */
    public function path(string $name): ?string
    {
        if (preg_match(self::MUSTER, $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $pfad = $this->backupDir . '/' . $name;
        if (!is_file($pfad) || is_link($pfad) || !is_readable($pfad)) {
            return null;
        }

        return $pfad;
    }
}
