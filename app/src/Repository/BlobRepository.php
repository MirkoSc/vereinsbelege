<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Blob;
use App\Domain\BlobStorage;

/**
 * The `file_blob` and `file_blob_chunk` tables (docs/spec/02-datenmodell.md
 * "Dateien"). SQL lives in repositories only, prepared statements only.
 *
 * A blob is written in two steps, because both its content and its metadata
 * need the row id: insertDraft() reserves the row, complete() turns it into a
 * readable blob once the stream is through. A row without `cipher_sha256` is
 * a write that broke off.
 */
final readonly class BlobRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Reserves a blob row. The content follows; the checksum makes it
     * readable.
     */
    public function insertDraft(
        BlobStorage $storage,
        ?string $fsName,
        string $dekSealed,
        string $header,
        ?\DateTimeImmutable $now = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO file_blob (storage, fs_name, size, dek_sealed, header, created_at)
             VALUES (?, ?, 0, ?, ?, ?)',
        );
        $stmt->bindValue(1, $storage->value);
        $stmt->bindValue(2, $fsName);
        $stmt->bindValue(3, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $header, \PDO::PARAM_LOB);
        $stmt->bindValue(5, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Plaintext length, ciphertext checksum and the encrypted metadata. */
    public function complete(int $id, int $size, string $cipherSha256, ?string $metaEnc): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE file_blob SET size = ?, cipher_sha256 = ?, meta_enc = ? WHERE id = ?',
        );
        $stmt->bindValue(1, $size, \PDO::PARAM_INT);
        $stmt->bindValue(2, $cipherSha256, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $metaEnc, $metaEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(4, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $id): ?Blob
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_blob WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : Blob::fromRow($row);
    }

    /** Chunk rows go with the row (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM file_blob WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function insertChunk(int $blobId, int $seq, string $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO file_blob_chunk (blob_id, seq, data) VALUES (?, ?, ?)');
        $stmt->bindValue(1, $blobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $seq, \PDO::PARAM_INT);
        $stmt->bindValue(3, $data, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * One chunk at a time, on purpose: PDO buffers a whole result set, so
     * iterating "SELECT data ... ORDER BY seq" would pull the entire file
     * into memory - exactly what streaming is meant to avoid.
     */
    public function findChunk(int $blobId, int $seq): ?string
    {
        $stmt = $this->pdo->prepare('SELECT data FROM file_blob_chunk WHERE blob_id = ? AND seq = ?');
        $stmt->execute([$blobId, $seq]);
        $data = $stmt->fetchColumn();

        return $data === false ? null : (string) $data;
    }

    public function countChunks(int $blobId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM file_blob_chunk WHERE blob_id = ?');
        $stmt->execute([$blobId]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteChunks(int $blobId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM file_blob_chunk WHERE blob_id = ?');
        $stmt->execute([$blobId]);
    }
}
