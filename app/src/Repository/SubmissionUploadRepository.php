<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `submission_upload` table (docs/spec/02-datenmodell.md "Fachdaten",
 * issue #24/M4-2): which blobs a public form token has uploaded so far, until
 * a finished submission claims them.
 *
 * A row here is the only thing that ties an anonymously uploaded blob to the
 * visit that created it - `form_hash` is the same hash
 * App\Service\Submission\FormTokenData::hash() computes from the token, so
 * neither side needs a session or a lookup by anything secret. Rows for
 * blobs a submission claimed are deleted in the same transaction
 * (App\Service\Submission\SubmissionService); rows nobody claimed within 24 h
 * are swept by App\Service\Cron\SubmissionUploadCleanupTask, together with
 * the blob itself.
 */
final readonly class SubmissionUploadRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $blobId, string $formHash, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO submission_upload (blob_id, form_hash, created_at) VALUES (?, ?, ?)',
        );
        $stmt->bindValue(1, $blobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $formHash, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->execute();
    }

    /**
     * @return list<int> blob ids uploaded under this token, in the order
     *         they were finished
     */
    public function blobIdsForFormHash(string $formHash): array
    {
        $stmt = $this->pdo->prepare('SELECT blob_id FROM submission_upload WHERE form_hash = ? ORDER BY blob_id');
        $stmt->bindValue(1, $formHash, \PDO::PARAM_LOB);
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Called once a submission has claimed every blob it needs. */
    public function deleteForFormHash(string $formHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM submission_upload WHERE form_hash = ?');
        $stmt->bindValue(1, $formHash, \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * Releases exactly the blobs a capture claimed (issue #28/M4-6): pages
     * the person uploaded and removed again stay behind for the cron, which
     * deletes them with their blob - deleteForFormHash() would orphan them.
     *
     * @param list<int> $blobIds
     */
    public function deleteBlobIds(array $blobIds): void
    {
        if ($blobIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare(sprintf(
            'DELETE FROM submission_upload WHERE blob_id IN (%s)',
            implode(', ', array_fill(0, count($blobIds), '?')),
        ));
        foreach (array_values($blobIds) as $i => $blobId) {
            $stmt->bindValue($i + 1, $blobId, \PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    /**
     * Blobs nobody's submission claimed before the cutoff - the cron's 24 h
     * rule (docs/spec/03-erfassung-und-ki.md section 4, same window as
     * App\Service\Upload\UploadService::cleanup()).
     *
     * @return list<int>
     */
    public function olderThan(\DateTimeImmutable $cutoff, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT blob_id FROM submission_upload WHERE created_at < ? ORDER BY created_at LIMIT %d',
            $limit,
        ));
        $stmt->bindValue(1, $cutoff->format(self::FORMAT));
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
