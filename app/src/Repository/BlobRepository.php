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

    /**
     * How much lies on which shelf, for the storage admin page (M2-5).
     * `size` is the plaintext length, a structural column - the sum says how
     * big the archive is, not what is in it.
     *
     * Incomplete rows (no checksum) are counted apart: they are uploads that
     * broke off, and the switch chain leaves them where they are.
     *
     * @return array<string, array{anzahl: int, bytes: int, entwuerfe: int}>
     */
    public function inventory(): array
    {
        $stmt = $this->pdo->query(
            'SELECT storage,
                    SUM(cipher_sha256 IS NOT NULL) AS anzahl,
                    COALESCE(SUM(CASE WHEN cipher_sha256 IS NOT NULL THEN size ELSE 0 END), 0) AS bytes,
                    SUM(cipher_sha256 IS NULL) AS entwuerfe
             FROM file_blob
             GROUP BY storage',
        );

        $inventory = [];
        foreach ($stmt as $row) {
            $inventory[(string) $row['storage']] = [
                'anzahl' => (int) $row['anzahl'],
                'bytes' => (int) $row['bytes'],
                'entwuerfe' => (int) $row['entwuerfe'],
            ];
        }

        return $inventory;
    }

    /**
     * Finished blobs that are not on the target backend yet - the work list
     * of the switch chain (M2-5). Ordered by id so an interrupted run picks
     * up where it stopped without remembering anything.
     *
     * `afterId` is the cursor within one request: a blob that could not be
     * moved stays in the result of the next call, and without the cursor a
     * single broken file would be retried until the time budget is gone.
     *
     * @return list<Blob>
     */
    public function findForeign(BlobStorage $target, int $limit, int $afterId = 0): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM file_blob
             WHERE storage <> ? AND cipher_sha256 IS NOT NULL AND id > ?
             ORDER BY id LIMIT %d',
            $limit,
        ));
        $stmt->bindValue(1, $target->value);
        $stmt->bindValue(2, $afterId, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Blob::fromRow(...), $stmt->fetchAll());
    }

    /** How many finished blobs still have to move (the chain's "offen"). */
    public function countForeign(BlobStorage $target): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM file_blob WHERE storage <> ? AND cipher_sha256 IS NOT NULL',
        );
        $stmt->execute([$target->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The next finished blobs after `afterId` - the cursor of the integrity
     * check, which walks the whole table in short requests.
     *
     * @return list<Blob>
     */
    public function findCompleteAfter(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM file_blob
             WHERE id > ? AND cipher_sha256 IS NOT NULL
             ORDER BY id LIMIT %d',
            $limit,
        ));
        $stmt->bindValue(1, $afterId, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Blob::fromRow(...), $stmt->fetchAll());
    }

    public function countComplete(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM file_blob WHERE cipher_sha256 IS NOT NULL')
            ->fetchColumn();
    }

    /**
     * Hands a blob its file name before a single byte is written, so a file
     * left behind by a broken run is always findable through its row.
     */
    public function setFsName(int $id, ?string $fsName): void
    {
        $stmt = $this->pdo->prepare('UPDATE file_blob SET fs_name = ? WHERE id = ?');
        $stmt->bindValue(1, $fsName, $fsName === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** The moment a blob changes shelf - only after its copy was verified. */
    public function setStorage(int $id, BlobStorage $storage): void
    {
        $stmt = $this->pdo->prepare('UPDATE file_blob SET storage = ? WHERE id = ?');
        $stmt->bindValue(1, $storage->value);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Chunk rows of blobs that live in the file system by now - leftovers of
     * a run that died between the flip and the delete.
     */
    public function deleteStrayChunks(): int
    {
        $stmt = $this->pdo->query(
            "DELETE c FROM file_blob_chunk c
             JOIN file_blob b ON b.id = c.blob_id
             WHERE b.storage = 'fs'",
        );

        return $stmt->rowCount();
    }

    /**
     * Blobs that live in the database but still carry a file name: their
     * file is the leftover, and the name is how it gets deleted.
     *
     * @return list<Blob>
     */
    public function findStrayFiles(int $limit): array
    {
        $stmt = $this->pdo->query(sprintf(
            "SELECT * FROM file_blob WHERE storage = 'db' AND fs_name IS NOT NULL ORDER BY id LIMIT %d",
            $limit,
        ));

        return array_map(Blob::fromRow(...), $stmt->fetchAll());
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
