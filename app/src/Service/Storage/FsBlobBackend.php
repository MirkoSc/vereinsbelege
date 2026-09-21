<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\Blob;

/**
 * Storage backend `fs`: ciphertext files below shared/var/blobs/, the default
 * of a fresh installation (decision E-06).
 *
 * The name of a file is a random id and nothing else - never the uploaded
 * name, never an id one could count up. Whoever gets to read the FTP account
 * (threat model in docs/spec/01-sicherheit.md) finds a directory of
 * indistinguishable ciphertext.
 *
 * Files are spread over 256 subdirectories by the first two characters of
 * that id: a club produces thousands of files a year, and a single flat
 * directory of that size is slow to list on a shared host.
 */
final readonly class FsBlobBackend implements BlobBackend
{
    /** How much ciphertext one fread() hands on. */
    private const int READ_BYTES = 256 * 1024;

    public function __construct(private string $blobDir)
    {
    }

    /** 128 random bits, hex - not derived from anything about the file. */
    public static function newName(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function open(Blob $blob): BlobSink
    {
        $path = $this->path($blob);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new BlobException('Blob-Verzeichnis kann nicht angelegt werden.');
        }

        return new FsBlobSink($path);
    }

    /**
     * @return iterable<string>
     */
    public function read(Blob $blob): iterable
    {
        $path = $this->path($blob);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new BlobException(sprintf('Blob %d is missing from the file system.', $blob->id));
        }

        try {
            while (!feof($handle)) {
                $piece = fread($handle, self::READ_BYTES);
                if ($piece === false) {
                    throw new BlobException(sprintf('Blob %d could not be read.', $blob->id));
                }
                if ($piece !== '') {
                    yield $piece;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(Blob $blob): void
    {
        $path = $this->path($blob);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(Blob $blob): bool
    {
        return is_file($this->path($blob));
    }

    private function path(Blob $blob): string
    {
        $name = $blob->fsName ?? throw new BlobException(sprintf('Blob %d has no file name.', $blob->id));
        // The name comes from newName(); anything else has no business
        // building a path out of it.
        if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1) {
            throw new BlobException(sprintf('Blob %d has an unusable file name.', $blob->id));
        }

        return $this->blobDir . '/' . substr($name, 0, 2) . '/' . $name;
    }
}
