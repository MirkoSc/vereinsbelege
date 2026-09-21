<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Repository\BlobRepository;

/**
 * Collects ciphertext until a chunk row is full and writes it away. Only the
 * current chunk is in memory, never the whole file.
 *
 * commit() is not a transaction: the blob counts as readable once
 * `file_blob.cipher_sha256` is set, and until then the chunk rows are a
 * draft that discard() (or the cron, M2-4) removes.
 */
final class DbBlobSink implements BlobSink
{
    private string $buffer = '';

    private int $seq = 0;

    private bool $closed = false;

    public function __construct(
        private readonly BlobRepository $repository,
        private readonly int $blobId,
    ) {
    }

    public function write(string $ciphertext): void
    {
        $this->assertOpen();

        $this->buffer .= $ciphertext;
        while (strlen($this->buffer) >= DbBlobBackend::CHUNK_BYTES) {
            $this->store(substr($this->buffer, 0, DbBlobBackend::CHUNK_BYTES));
            $this->buffer = substr($this->buffer, DbBlobBackend::CHUNK_BYTES);
        }
    }

    public function commit(): void
    {
        $this->assertOpen();

        if ($this->buffer !== '') {
            $this->store($this->buffer);
            $this->buffer = '';
        }
        $this->closed = true;
    }

    public function discard(): void
    {
        $this->buffer = '';
        $this->closed = true;
        $this->repository->deleteChunks($this->blobId);
    }

    private function store(string $data): void
    {
        $this->repository->insertChunk($this->blobId, $this->seq, $data);
        $this->seq++;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new BlobException('The blob stream is already closed.');
        }
    }
}
