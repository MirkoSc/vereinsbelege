<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\Blob;
use App\Repository\BlobRepository;

/**
 * Storage backend `db`: the ciphertext stream as rows in `file_blob_chunk`
 * (decision E-06, alternative to the default `fs`).
 *
 * Worth having on a host whose file system is the weaker spot, or whose
 * backup covers the database only. Price: dumps grow with every receipt.
 *
 * The chunk size is the backend's own - it has nothing to do with the 64 KiB
 * crypto blocks, because {@see BlobStreamReader} buffers to block boundaries
 * itself.
 */
final readonly class DbBlobBackend implements BlobBackend
{
    /** Ciphertext per row, well below the MEDIUMBLOB limit of 1 MiB. */
    public const int CHUNK_BYTES = 256 * 1024;

    public function __construct(private BlobRepository $repository)
    {
    }

    public function open(Blob $blob): BlobSink
    {
        return new DbBlobSink($this->repository, $blob->id);
    }

    /**
     * @return iterable<string>
     */
    public function read(Blob $blob): iterable
    {
        for ($seq = 0; ; $seq++) {
            $data = $this->repository->findChunk($blob->id, $seq);
            if ($data === null) {
                break;
            }
            yield $data;
        }

        if ($seq === 0) {
            throw new BlobException(sprintf('Blob %d has no chunks in the database.', $blob->id));
        }
    }

    public function delete(Blob $blob): void
    {
        $this->repository->deleteChunks($blob->id);
    }

    public function exists(Blob $blob): bool
    {
        return $this->repository->countChunks($blob->id) > 0;
    }
}
