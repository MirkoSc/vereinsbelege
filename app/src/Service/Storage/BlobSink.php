<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * An open write stream of a backend. Bytes go in piece by piece; commit()
 * makes them the blob's content, discard() throws the half-written attempt
 * away.
 *
 * A blob becomes readable only after both commit() and the checksum in
 * `file_blob` - so a request that dies in the middle leaves a draft row, not
 * a corrupt receipt.
 */
interface BlobSink
{
    public function write(string $ciphertext): void;

    public function commit(): void;

    public function discard(): void;
}
