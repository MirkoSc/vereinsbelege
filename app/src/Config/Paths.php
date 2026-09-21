<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Resolves all filesystem paths relative to the release root.
 *
 * Production layout on the shared hosting provider (CLAUDE.md section 2):
 *   /web      docroot shim
 *   /current  active release = release root
 *   /shared   persistent data, sibling of the release root
 *
 * The dev docker setup mirrors this layout exactly.
 */
final readonly class Paths
{
    public function __construct(public string $releaseRoot)
    {
    }

    public function sharedDir(): string
    {
        return dirname($this->releaseRoot) . '/shared';
    }

    public function configFile(): string
    {
        return $this->sharedDir() . '/config.php';
    }

    public function publicDir(): string
    {
        return $this->releaseRoot . '/public';
    }

    public function viewsDir(): string
    {
        return $this->releaseRoot . '/app/views';
    }

    public function migrationsDir(): string
    {
        return $this->releaseRoot . '/migrations';
    }

    public function versionFile(): string
    {
        return $this->releaseRoot . '/VERSION';
    }

    public function varDir(): string
    {
        return $this->sharedDir() . '/var';
    }

    /**
     * Set while the updater renames releases; checked by the docroot shim,
     * which is why it sits directly in shared/ and not below var/.
     */
    public function maintenanceFlagFile(): string
    {
        return $this->sharedDir() . '/maintenance.flag';
    }

    /**
     * State of the running update step chain. A file and not a table: the
     * chain has to survive the rename of current/ and be readable by a
     * release whose migrations have not run yet.
     */
    public function updateStateFile(): string
    {
        return $this->sharedDir() . '/update_state.json';
    }

    /**
     * checksums.txt of the installed release, kept by the updater for the
     * code integrity check (docs/spec/01-sicherheit.md section 8).
     */
    public function releaseChecksumsFile(): string
    {
        return $this->sharedDir() . '/release_checksums.txt';
    }

    /**
     * Release channel chosen in setup.php, handed over to the installer -
     * setup.php runs before there is a database to write the setting into.
     * Deleted once the setting exists.
     */
    public function setupChannelFile(): string
    {
        return $this->sharedDir() . '/setup_kanal.txt';
    }

    /** The docroot, sibling of the release root: shim, .htaccess, .user.ini. */
    public function webDir(): string
    {
        return dirname($this->releaseRoot) . '/web';
    }

    public function logFile(): string
    {
        return $this->varDir() . '/log/app.log';
    }

    /** Storage backend "fs": encrypted blobs, never plaintext (M2-3). */
    public function blobDir(): string
    {
        return $this->varDir() . '/blobs';
    }

    /** Backup ZIPs (docs/spec/06-betrieb.md section 2); survives updates. */
    public function backupDir(): string
    {
        return $this->varDir() . '/backups';
    }

    public function tmpDir(): string
    {
        return $this->varDir() . '/tmp';
    }

    /**
     * Chunks of uploads that are still running (M2-4). The one place where
     * plaintext may lie around, and only until the closing request encrypts
     * it - orphans go after 24 h (docs/spec/03-erfassung-und-ki.md section 4).
     */
    public function uploadDir(): string
    {
        return $this->tmpDir() . '/upload';
    }
}
