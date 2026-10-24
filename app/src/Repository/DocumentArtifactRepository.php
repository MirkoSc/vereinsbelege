<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ArtifactKind;
use App\Domain\DocumentArtifact;
use App\Domain\JobExecutor;
use App\Domain\Zugriffsbereich;

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

    /**
     * The next documents within $bereich (scope on the document like the
     * inbox: `created_at`, `cost_center_id`) that have artifacts of $kinds
     * after id $nachId, in the order of their newest such artifact (`bis`) -
     * what the full-text index (issue #94, App\Service\Suche\Volltextsuche)
     * takes in next. Ids only; nothing is decrypted here.
     *
     * @param list<ArtifactKind> $kinds
     *
     * @return list<array{document_id: int, bis: int}>
     */
    public function naechsteSuchdokumente(int $nachId, array $kinds, Zugriffsbereich $bereich, int $limit): array
    {
        if ($kinds === []) {
            return [];
        }
        [$sql, $parameter] = self::suchquellenBedingung('a.id > ?', $nachId, $kinds, $bereich);
        $stmt = $this->pdo->prepare(
            'SELECT a.document_id, MAX(a.id) AS bis FROM document_artifact a JOIN document d ON d.id = a.document_id
             WHERE ' . $sql . ' GROUP BY a.document_id ORDER BY bis LIMIT ' . max(1, $limit),
        );
        $stmt->execute($parameter);

        return array_map(
            static fn(array $row): array => ['document_id' => (int) $row['document_id'], 'bis' => (int) $row['bis']],
            $stmt->fetchAll(),
        );
    }

    /**
     * Which documents within $bereich have artifacts of $kinds up to id
     * $bisId, as a short signature (count and sum of their ids) - the full-
     * text index compares it to notice an old document entering or leaving
     * a cost-center scope (issue #94).
     *
     * @param list<ArtifactKind> $kinds
     */
    public function suchdokumenteBis(int $bisId, array $kinds, Zugriffsbereich $bereich): string
    {
        if ($kinds === []) {
            return '0:0';
        }
        [$sql, $parameter] = self::suchquellenBedingung('a.id <= ?', $bisId, $kinds, $bereich);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT a.document_id), COALESCE(SUM(DISTINCT a.document_id), 0)
             FROM document_artifact a JOIN document d ON d.id = a.document_id WHERE ' . $sql,
        );
        $stmt->execute($parameter);
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        return $row === false ? '0:0' : (int) $row[0] . ':' . (int) $row[1];
    }

    /**
     * How many documents within $bereich have artifacts of $kinds after id
     * $nachId - the "still to index" of the full-text index (issue #94).
     *
     * @param list<ArtifactKind> $kinds
     */
    public function zaehleSuchquellenNach(int $nachId, array $kinds, Zugriffsbereich $bereich): int
    {
        if ($kinds === []) {
            return 0;
        }
        [$sql, $parameter] = self::suchquellenBedingung('a.id > ?', $nachId, $kinds, $bereich);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT a.document_id) FROM document_artifact a JOIN document d ON d.id = a.document_id WHERE ' . $sql,
        );
        $stmt->execute($parameter);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param string $grenze the cursor condition, `a.id > ?` or `a.id <= ?` -
     *        from code only, never from a request
     * @param list<ArtifactKind> $kinds
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function suchquellenBedingung(string $grenze, int $id, array $kinds, Zugriffsbereich $bereich): array
    {
        [$scope, $scopeParameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');

        return [
            $grenze . ' AND a.kind IN (' . implode(', ', array_fill(0, count($kinds), '?')) . ') AND ' . $scope,
            [$id, ...array_map(static fn(ArtifactKind $k): string => $k->value, $kinds), ...$scopeParameter],
        ];
    }
}
