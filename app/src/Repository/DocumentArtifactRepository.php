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
     * Kind prefix of an artifact the worker announced but has not committed
     * yet (issue #52/M7b-2, migrations/041_worker_grant.sql). Every reader
     * asks for a concrete kind, so such a row stays invisible until
     * festschreiben() drops the prefix - and it still has its id, which the
     * worker needs for the AAD of `data_enc`.
     */
    public const string PENDING = '~';

    /**
     * Kind prefix of a worker artifact that did not open or did not have the
     * expected shape when its result was taken over (issue #53,
     * App\Service\Worker\WorkerUebernahme): hidden from every reader like
     * a reservation, but kept - the admin can see that the worker delivered
     * something broken, and "OCR nachholen" does not repeat a run that
     * produced it.
     */
    public const string DEFEKT = '!';

    /**
     * Hides a committed worker artifact for good (DEFEKT). Only the worker's
     * own rows, never a reservation or one of the session's.
     */
    public function alsDefektMarkieren(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE document_artifact SET kind = CONCAT(?, kind) WHERE id = ? AND producer = ? AND kind NOT LIKE ? AND kind NOT LIKE ?",
        );
        $stmt->execute([self::DEFEKT, $id, JobExecutor::Worker->value, self::PENDING . '%', self::DEFEKT . '%']);

        return $stmt->rowCount() === 1;
    }

    /**
     * Hides every committed worker artifact of job $jobId (DEFEKT) - for a
     * worker job that fell back or failed: nobody checked them, and a job
     * moved to the browser/session keeps its id, so its new run must not
     * pick them up as its own. Returns how many.
     */
    public function workerLaufVerwerfen(int $jobId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE document_artifact SET kind = CONCAT(?, kind) WHERE job_id = ? AND producer = ? AND kind NOT LIKE ? AND kind NOT LIKE ?',
        );
        $stmt->execute([self::DEFEKT, $jobId, JobExecutor::Worker->value, self::PENDING . '%', self::DEFEKT . '%']);

        return $stmt->rowCount();
    }

    /**
     * The blob ids of job $jobId's committed worker artifacts (not
     * reserved, not defective) - an aborted attempt's results, which
     * App\Service\Worker\WorkerArtefakte::abgebrochenenVersuchVerwerfen()
     * deletes with their blobs (`ON DELETE CASCADE` takes the rows along).
     *
     * @return list<int>
     */
    public function workerLaufBlobIds(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT blob_id FROM document_artifact
             WHERE job_id = ? AND producer = ? AND blob_id IS NOT NULL AND kind NOT LIKE ? AND kind NOT LIKE ?',
        );
        $stmt->execute([$jobId, JobExecutor::Worker->value, self::PENDING . '%', self::DEFEKT . '%']);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Deletes job $jobId's committed worker artifacts without a blob
     * (`text`, `extraction`) - the other half of an aborted attempt.
     * Returns how many.
     */
    public function workerLaufDatenLoeschen(int $jobId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM document_artifact
             WHERE job_id = ? AND producer = ? AND blob_id IS NULL AND kind NOT LIKE ? AND kind NOT LIKE ?',
        );
        $stmt->execute([$jobId, JobExecutor::Worker->value, self::PENDING . '%', self::DEFEKT . '%']);

        return $stmt->rowCount();
    }

    /** How many worker artifacts are marked defective - for the admin page. */
    public function zaehleDefekte(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document_artifact WHERE producer = ? AND kind LIKE ?');
        $stmt->execute([JobExecutor::Worker->value, self::DEFEKT . '%']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Reservations (PENDING) with an id up to $bisId. The full-text index
     * remembers them when its cursor passes them: their id is older than
     * the moment they become visible (issue #53, review of #52).
     *
     * @return list<int>
     */
    public function reservierteBis(int $bisId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM document_artifact WHERE id <= ? AND kind LIKE ? ORDER BY id');
        $stmt->execute([$bisId, self::PENDING . '%']);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Which of $ids are still reservations.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function nochReserviert(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id FROM document_artifact WHERE id IN (%s) AND kind LIKE ? ORDER BY id',
            implode(',', array_fill(0, count($ids), '?')),
        ));
        $stmt->execute([...$ids, self::PENDING . '%']);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The documents within $bereich of those of $ids that are visible now
     * with one of $kinds - former reservations the full-text index has
     * still to take in.
     *
     * @param list<int> $ids
     * @param list<ArtifactKind> $kinds
     * @return list<int>
     */
    public function sichtbarGeworden(array $ids, array $kinds, Zugriffsbereich $bereich): array
    {
        if ($ids === [] || $kinds === []) {
            return [];
        }
        [$scope, $scopeParameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT DISTINCT a.document_id FROM document_artifact a JOIN document d ON d.id = a.document_id
             WHERE a.id IN (%s) AND a.kind IN (%s) AND %s ORDER BY a.document_id',
            implode(',', array_fill(0, count($ids), '?')),
            implode(',', array_fill(0, count($kinds), '?')),
            $scope,
        ));
        $stmt->execute([...$ids, ...array_map(static fn(ArtifactKind $k): string => $k->value, $kinds), ...$scopeParameter]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Reserves the row of a worker upload: kind with PENDING prefix, no
     * payload yet. Null when the unique key (document, kind, job, seq) is
     * taken - by the same announcement (a repeat) or a committed artifact.
     */
    public function reserviere(int $documentId, ArtifactKind $kind, int $seq, string $dekSealed, int $jobId, \DateTimeImmutable $now): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO document_artifact
                    (document_id, kind, seq, blob_id, dek_sealed, data_enc, producer, job_id, created_at)
                 VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?)',
            );
            $stmt->execute([$documentId, self::PENDING . $kind->value, $seq, $dekSealed, JobExecutor::Worker->value, $jobId, $now->format(self::FORMAT)]);

            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The id of the reserved, not yet committed row of this upload.
     */
    public function reserviert(int $documentId, ArtifactKind $kind, int $jobId, int $seq): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM document_artifact WHERE document_id = ? AND kind = ? AND job_id = ? AND seq = ?',
        );
        $stmt->execute([$documentId, self::PENDING . $kind->value, $jobId, $seq]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Commits a reserved worker upload: payload in, prefix off - one
     * statement, so a reader sees either nothing or the whole artifact.
     * False when the row is no longer a reservation of this kind.
     */
    public function festschreiben(int $id, ArtifactKind $kind, ?int $blobId, ?string $dataEnc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE document_artifact SET kind = ?, blob_id = ?, data_enc = ? WHERE id = ? AND kind = ?',
        );
        $stmt->bindValue(1, $kind->value);
        $stmt->bindValue(2, $blobId, $blobId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $dataEnc, $dataEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(4, $id, \PDO::PARAM_INT);
        $stmt->bindValue(5, self::PENDING . $kind->value);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * The raw row of a worker upload, reserved or committed - kind as
     * stored (with or without PENDING), so a repeated commit can tell
     * "already done" from "never announced".
     *
     * @return ?array{document_id: int, kind: string, job_id: ?int, dek_sealed: string}
     */
    public function rohzeile(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT document_id, kind, job_id, dek_sealed FROM document_artifact WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'document_id' => (int) $row['document_id'],
            'kind' => (string) $row['kind'],
            'job_id' => $row['job_id'] === null ? null : (int) $row['job_id'],
            'dek_sealed' => (string) $row['dek_sealed'],
        ] : null;
    }

    /** Drops a reservation (never a committed artifact). */
    public function reservierungVerwerfen(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM document_artifact WHERE id = ? AND kind LIKE ?');
        $stmt->execute([$id, self::PENDING . '%']);
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
     * a cost-center scope (issue #94). $ausser: artifact ids the index has
     * not taken in yet although they lie below the cursor (former
     * reservations, issue #53) - left out until it has.
     *
     * @param list<ArtifactKind> $kinds
     * @param list<int> $ausser
     */
    public function suchdokumenteBis(int $bisId, array $kinds, Zugriffsbereich $bereich, array $ausser = []): string
    {
        if ($kinds === []) {
            return '0:0';
        }
        [$sql, $parameter] = self::suchquellenBedingung('a.id <= ?', $bisId, $kinds, $bereich);
        if ($ausser !== []) {
            $sql .= sprintf(' AND a.id NOT IN (%s)', implode(',', array_fill(0, count($ausser), '?')));
            array_push($parameter, ...$ausser);
        }
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
