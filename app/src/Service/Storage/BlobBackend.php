<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\Blob;

/**
 * A shelf for blob ciphertext (decision E-06). Knows nothing about keys,
 * chunks or metadata - it stores a byte stream and hands it back in the same
 * order. That is the whole reason switching backends (M2-5) is a copy and not
 * a re-encryption.
 *
 * Implementations never load a whole file: they write and read in pieces.
 */
interface BlobBackend
{
    /** Opens a new, empty stream for a blob row that already exists. */
    public function open(Blob $blob): BlobSink;

    /**
     * The stored ciphertext, in pieces. The size of a piece is the backend's
     * business - {@see BlobStreamReader} buffers to block boundaries.
     *
     * @return iterable<string>
     */
    public function read(Blob $blob): iterable;

    public function delete(Blob $blob): void;

    public function exists(Blob $blob): bool;
}
