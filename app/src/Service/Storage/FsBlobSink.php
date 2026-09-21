<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Writes the ciphertext of a blob into a temporary file next to its target
 * and renames it on commit. The blob file therefore either does not exist or
 * is complete - a request that dies halfway leaves a `.part` behind, never a
 * file that looks readable and is not.
 */
final class FsBlobSink implements BlobSink
{
    /** @var ?resource */
    private $handle;

    private readonly string $tempPath;

    public function __construct(private readonly string $path)
    {
        $this->tempPath = $path . '.' . bin2hex(random_bytes(4)) . '.part';
        $handle = @fopen($this->tempPath, 'wb');
        if ($handle === false) {
            throw new BlobException('Blob-Verzeichnis ist nicht beschreibbar.');
        }
        $this->handle = $handle;
    }

    public function write(string $ciphertext): void
    {
        if ($this->handle === null) {
            throw new BlobException('The blob file is already closed.');
        }
        if ($ciphertext === '') {
            return;
        }
        if (fwrite($this->handle, $ciphertext) !== strlen($ciphertext)) {
            throw new BlobException('Blob konnte nicht vollständig geschrieben werden.');
        }
    }

    public function commit(): void
    {
        if ($this->handle === null) {
            throw new BlobException('The blob file is already closed.');
        }

        fclose($this->handle);
        $this->handle = null;

        if (!rename($this->tempPath, $this->path)) {
            @unlink($this->tempPath);
            throw new BlobException('Blob konnte nicht abgelegt werden.');
        }

        @chmod($this->path, 0664);
    }

    public function discard(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }
}
