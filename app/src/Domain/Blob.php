<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `file_blob`. Nothing in here is readable without the vault: the
 * data key is sealed, the metadata encrypted, and the stored bytes are
 * ciphertext.
 */
final readonly class Blob
{
    public function __construct(
        public int $id,
        public BlobStorage $storage,
        public ?string $fsName,
        public int $size,
        public ?string $cipherSha256,
        public string $dekSealed,
        public string $header,
        public ?string $metaEnc,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            storage: BlobStorage::from((string) $row['storage']),
            fsName: $row['fs_name'] === null ? null : (string) $row['fs_name'],
            size: (int) $row['size'],
            cipherSha256: $row['cipher_sha256'] === null ? null : (string) $row['cipher_sha256'],
            dekSealed: (string) $row['dek_sealed'],
            header: (string) $row['header'],
            metaEnc: $row['meta_enc'] === null ? null : (string) $row['meta_enc'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * A blob whose stream was written to the end. Without the checksum the
     * write broke off halfway - the row exists, the content does not.
     */
    public function isComplete(): bool
    {
        return $this->cipherSha256 !== null;
    }
}
