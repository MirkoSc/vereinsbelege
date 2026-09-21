<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where the ciphertext of a blob lies (decision E-06, docs/spec/02-datenmodell.md
 * "Dateien").
 *
 * Both backends store the very same byte stream; only the shelf differs. That
 * is what makes switching them a step chain over rows instead of a migration
 * (M2-5).
 */
enum BlobStorage: string
{
    /** Chunks in `file_blob_chunk` - everything in the database, nothing on disk. */
    case Db = 'db';

    /** Files below shared/var/blobs/, always ciphertext, random names. */
    case Fs = 'fs';

    /**
     * The default of a fresh installation: the file system keeps the database
     * (and every dump of it) small, and the content is encrypted either way.
     */
    public static function default(): self
    {
        return self::Fs;
    }

    /**
     * Reads the admin setting. An unknown value falls back to the default
     * instead of throwing: a typo in `setting` must not make the whole
     * receipt storage unreachable.
     */
    public static function fromSetting(string $value): self
    {
        return self::tryFrom($value) ?? self::default();
    }
}
