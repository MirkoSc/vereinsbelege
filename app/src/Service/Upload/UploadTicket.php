<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * What the browser gets back when an upload is opened: where to send the
 * chunks and how to cut the file.
 */
final readonly class UploadTicket
{
    public function __construct(
        public string $id,
        public int $groesse,
        public int $chunks,
        public int $chunkBytes,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'groesse' => $this->groesse,
            'chunks' => $this->chunks,
            'chunk_bytes' => $this->chunkBytes,
        ];
    }
}
