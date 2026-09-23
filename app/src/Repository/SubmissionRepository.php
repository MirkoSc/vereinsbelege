<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\SubmissionStatus;

/**
 * The `submission` table (docs/spec/02-datenmodell.md "Fachdaten", issue
 * #24/M4-2). SQL lives in repositories only, prepared statements only.
 *
 * A row is written in two steps, the same shape as App\Repository\
 * BlobRepository: insertDraft() reserves the row (form_hash unique, so a
 * repeated request with the same token is caught here), complete() fills in
 * the reference number and the sealed payload once both are known. Between
 * the two the row exists but reference_code is NULL - App\Service\Submission\
 * SubmissionService never leaves it there past its own transaction.
 */
final readonly class SubmissionRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The reference already issued for this token, if the visitor's browser
     * is retrying a request the server already finished - the same answer,
     * not a second row.
     */
    public function findReferenzByFormHash(string $formHash): ?string
    {
        $stmt = $this->pdo->prepare('SELECT reference_code FROM submission WHERE form_hash = ?');
        $stmt->bindValue(1, $formHash, \PDO::PARAM_LOB);
        $stmt->execute();
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : (string) $wert;
    }

    public function insertDraft(string $formHash, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO submission (form_hash, received_at, status) VALUES (?, ?, ?)',
        );
        $stmt->bindValue(1, $formHash, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $now->format(self::FORMAT));
        $stmt->bindValue(3, SubmissionStatus::Eingegangen->value);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @throws \PDOException on a reference_code collision (SQLSTATE 23000) -
     *         the caller retries with the next candidate
     */
    public function complete(int $id, string $referenzCode, string $dekSealed, string $payloadEnc): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE submission SET reference_code = ?, dek_sealed = ?, payload_enc = ? WHERE id = ?',
        );
        $stmt->bindValue(1, $referenzCode);
        $stmt->bindValue(2, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $payloadEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The reference number of a submission by its id - what
     * App\Service\Document\PdfErzeugung puts into the generated PDF's title
     * (issue #26/M4-4). Structural, plaintext column, no vault needed.
     */
    public function findReferenzById(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT reference_code FROM submission WHERE id = ?');
        $stmt->bindValue(1, $id, \PDO::PARAM_INT);
        $stmt->execute();
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : (string) $wert;
    }

    /**
     * The highest reference serial already used this year, 0 when there is
     * none yet - the next candidate is this plus one
     * (App\Service\Submission\SubmissionService).
     */
    public function hoechsteLaufnummer(int $jahr): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(reference_code, '-', -1) AS UNSIGNED))
             FROM submission WHERE reference_code LIKE ?",
        );
        $stmt->bindValue(1, sprintf('R-%d-%%', $jahr));
        $stmt->execute();
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? 0 : (int) $wert;
    }
}
