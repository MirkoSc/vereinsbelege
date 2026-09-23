<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Document;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\OcrStatus;

/**
 * The `document` table (docs/spec/02-datenmodell.md "Fachdaten"). SQL lives
 * in repositories only, prepared statements only.
 *
 * insert() (issue #24/M4-2, the public submission writes the first row a
 * document ever gets) and find()/setzePdfBlob() (issue #26/M4-4, the
 * `pdf_erzeugen` job) exist so far: listing, further status transitions and
 * `content_bi` all need an unlocked vault or a review workflow that does not
 * exist before the inbox (M4-5).
 */
final readonly class DocumentRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<int> $originalBlobIds page order, as uploaded
     */
    public function insert(
        DocumentSource $source,
        ?int $submissionId,
        array $originalBlobIds,
        string $dekSealed,
        \DateTimeImmutable $now,
        DocumentStatus $status = DocumentStatus::Eingegangen,
        OcrStatus $ocrStatus = OcrStatus::Keine,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO document (source, submission_id, original_blob_ids, status, ocr_status, dek_sealed, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $source->value);
        $stmt->bindValue(2, $submissionId, $submissionId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, json_encode($originalBlobIds, JSON_THROW_ON_ERROR));
        $stmt->bindValue(4, $status->value);
        $stmt->bindValue(5, $ocrStatus->value);
        $stmt->bindValue(6, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Document
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : Document::fromRow($row);
    }

    /**
     * Fills in the generated PDF, but only into a document that does not
     * have one yet: two racing job attempts must not overwrite each other's
     * blob, and the loser has to know it lost so it can delete what it made
     * (App\Service\Document\PdfErzeugung). Returns whether this call is the
     * one that won.
     */
    public function setzePdfBlob(int $id, int $blobId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE document SET pdf_blob_id = ? WHERE id = ? AND pdf_blob_id IS NULL');
        $stmt->bindValue(1, $blobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }
}
