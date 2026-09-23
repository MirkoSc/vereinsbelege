<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\OcrStatus;

/**
 * The `document` table (docs/spec/02-datenmodell.md "Fachdaten"). SQL lives
 * in repositories only, prepared statements only.
 *
 * Only insert() exists yet (issue #24/M4-2, the public submission writes the
 * first row a document ever gets): listing, status transitions and
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
}
