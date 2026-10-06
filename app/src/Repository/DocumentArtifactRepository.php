<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ArtifactKind;
use App\Domain\DocumentArtifact;
use App\Domain\JobExecutor;

/**
 * The `document_artifact` table (docs/spec/02-datenmodell.md "Fachdaten",
 * issue #30/M4-8). SQL lives in repositories only, prepared statements only.
 */
final readonly class DocumentArtifactRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * One page of one rendering run. `vorhanden()` is meant to be checked
     * first (App\Service\Document\PdfRasterung): a repeated upload of the
     * same page must not fail here, it must simply not insert twice - the
     * unique key (`migrations/015_document_artifact.sql`) is the backstop,
     * not the primary defence.
     */
    public function insert(
        int $documentId,
        ArtifactKind $kind,
        int $seq,
        ?int $blobId,
        string $dekSealed,
        ?string $dataEnc,
        JobExecutor $producer,
        ?int $jobId,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO document_artifact
                (document_id, kind, seq, blob_id, dek_sealed, data_enc, producer, job_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $documentId,
            $kind->value,
            $seq,
            $blobId,
            $dekSealed,
            $dataEnc,
            $producer->value,
            $jobId,
            $now->format(self::FORMAT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Whether this exact page of this exact rendering run is already
     * stored - the idempotency check a retried or duplicated upload needs
     * (App\Api\RasterungController).
     */
    public function vorhanden(int $documentId, ArtifactKind $kind, int $jobId, int $seq): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM document_artifact WHERE document_id = ? AND kind = ? AND job_id = ? AND seq = ?',
        );
        $stmt->execute([$documentId, $kind->value, $jobId, $seq]);

        return $stmt->fetch() !== false;
    }

    /**
     * One artifact of one run, if it was stored - for a step that has to
     * pick up where a cut-off attempt of itself left off
     * (App\Service\Document\Texterkennung).
     */
    public function finde(int $documentId, ArtifactKind $kind, int $jobId, int $seq): ?DocumentArtifact
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_artifact WHERE document_id = ? AND kind = ? AND job_id = ? AND seq = ?',
        );
        $stmt->execute([$documentId, $kind->value, $jobId, $seq]);
        $row = $stmt->fetch();

        return $row === false ? null : DocumentArtifact::fromRow($row);
    }

    /**
     * The encrypted payload of an artifact that keeps it inline (`text`,
     * `extraction`). A separate write after insert(): the AAD binds the
     * ciphertext to the row id (docs/spec/01-sicherheit.md section 2), which
     * only exists once the row does.
     */
    public function setzeDaten(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE document_artifact SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Drops what other runs of this kind left for the document - for a kind
     * without blobs (`text`); the blob-backed `page_image` goes through
     * fremdeBlobIds() instead, so the blob is deleted with the row. "The
     * newest per kind counts" (docs/spec/02-datenmodell.md) then holds
     * without readers having to sort runs out.
     */
    public function loescheAndereLaeufe(int $documentId, ArtifactKind $kind, int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM document_artifact WHERE document_id = ? AND kind = ? AND (job_id IS NULL OR job_id != ?) AND blob_id IS NULL',
        );
        $stmt->execute([$documentId, $kind->value, $jobId]);
    }

    /**
     * @return list<DocumentArtifact> every artifact of this kind, in page order
     */
    public function fuerDokument(int $documentId, ArtifactKind $kind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_artifact WHERE document_id = ? AND kind = ? ORDER BY seq',
        );
        $stmt->execute([$documentId, $kind->value]);

        return array_map(DocumentArtifact::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The blob ids of a superseded rendering run (App\Service\Document\
     * PdfRasterung, once the current run is `fertig`): every artifact of
     * this document and kind that a DIFFERENT job produced. Deleting those
     * blobs (App\Service\Storage\BlobService::delete()) takes the rows with
     * it - `ON DELETE CASCADE` on `blob_id`.
     *
     * @return list<int>
     */
    public function fremdeBlobIds(int $documentId, ArtifactKind $kind, int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT blob_id FROM document_artifact
             WHERE document_id = ? AND kind = ? AND job_id != ? AND blob_id IS NOT NULL',
        );
        $stmt->execute([$documentId, $kind->value, $jobId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
