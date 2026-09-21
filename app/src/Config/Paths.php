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

    public function logFile(): string
    {
        return $this->varDir() . '/log/app.log';
    }

    /** Storage backend "fs": encrypted blobs, never plaintext (M2-3). */
    public function blobDir(): string
    {
        return $this->varDir() . '/blobs';
    }

    public function tmpDir(): string
    {
        return $this->varDir() . '/tmp';
    }
}
