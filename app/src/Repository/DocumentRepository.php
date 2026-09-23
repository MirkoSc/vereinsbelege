<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Document;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InboxItem;
use App\Domain\OcrStatus;
use App\Domain\Zugriffsbereich;
use App\Service\Inbox\InboxAnsicht;
use App\Service\Inbox\InboxFilter;

/**
 * The `document` table (docs/spec/02-datenmodell.md "Fachdaten"). SQL lives
 * in repositories only, prepared statements only.
 *
 * insert() (issue #24/M4-2, the public submission writes the first row a
 * document ever gets), find()/setzePdfBlob() (issue #26/M4-4, the
 * `pdf_erzeugen` job) and the inbox (issue #27/M4-5): the filtered list,
 * status changes and the cost center. Every inbox read takes the viewer's
 * App\Domain\Zugriffsbereich and filters in SQL (docs/spec/01-sicherheit.md
 * section 4) - a template never sees a row it must not show.
 */
final readonly class DocumentRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** The document plus what the inbox needs of its submission. */
    private const string INBOX_SELECT =
        'SELECT d.*, s.reference_code, s.received_at, s.dek_sealed AS submission_dek_sealed, s.payload_enc
         FROM document d LEFT JOIN submission s ON s.id = d.submission_id';

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
        ?int $costCenterId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO document (source, submission_id, original_blob_ids, status, ocr_status, dek_sealed, created_at, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $source->value);
        $stmt->bindValue(2, $submissionId, $submissionId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, json_encode($originalBlobIds, JSON_THROW_ON_ERROR));
        $stmt->bindValue(4, $status->value);
        $stmt->bindValue(5, $ocrStatus->value);
        $stmt->bindValue(6, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->bindValue(8, $costCenterId, $costCenterId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
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

    /**
     * The inbox list, newest first: the status group, period and cost center
     * of the filter, always narrowed to what $bereich allows (cost center
     * scope on `cost_center_id`, period scope on `created_at`).
     *
     * @return list<InboxItem>
     */
    public function inbox(InboxFilter $filter, Zugriffsbereich $bereich, \DateTimeImmutable $heute, int $limit): array
    {
        [$bedingungen, $parameter] = self::ansicht($filter->ansicht, $heute);

        if ($filter->von !== null) {
            $bedingungen[] = 'd.created_at >= ?';
            $parameter[] = $filter->von->format('Y-m-d');
        }
        if ($filter->bis !== null) {
            $bedingungen[] = 'd.created_at < ?';
            $parameter[] = $filter->bis->modify('+1 day')->format('Y-m-d');
        }
        if ($filter->kostenstelle === 0) {
            $bedingungen[] = 'd.cost_center_id IS NULL';
        } elseif ($filter->kostenstelle !== null) {
            $bedingungen[] = 'd.cost_center_id = ?';
            $parameter[] = $filter->kostenstelle;
        }

        [$scope, $scopeParameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');
        $bedingungen[] = $scope;
        array_push($parameter, ...$scopeParameter);

        $stmt = $this->pdo->prepare(
            self::INBOX_SELECT . ' WHERE ' . implode(' AND ', $bedingungen)
            . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . max(1, $limit),
        );
        $stmt->execute($parameter);

        return array_map(InboxItem::fromRow(...), $stmt->fetchAll());
    }

    /**
     * One inbox entry by id - null when it does not exist or lies outside
     * $bereich (the caller answers both with the same 404).
     */
    public function inboxItem(int $id, Zugriffsbereich $bereich): ?InboxItem
    {
        [$scope, $parameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');
        $stmt = $this->pdo->prepare(self::INBOX_SELECT . ' WHERE d.id = ? AND ' . $scope);
        $stmt->execute([$id, ...$parameter]);
        $row = $stmt->fetch();

        return $row === false ? null : InboxItem::fromRow($row);
    }

    /**
     * Changes the status, but only away from $von: two people deciding on
     * the same document at once must not both win, and the loser learns it
     * (false). The note and the Wiedervorlage date are replaced with what
     * the new status brings - null clears them.
     */
    public function wechsleStatus(
        int $id,
        DocumentStatus $von,
        DocumentStatus $nach,
        ?string $statusNoteEnc,
        ?\DateTimeImmutable $resubmitOn,
        ?int $userId,
        \DateTimeImmutable $now,
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE document SET status = ?, status_note_enc = ?, resubmit_on = ?, status_changed_at = ?, status_changed_by = ?
             WHERE id = ? AND status = ?',
        );
        $stmt->bindValue(1, $nach->value);
        $stmt->bindValue(2, $statusNoteEnc, $statusNoteEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(3, $resubmitOn?->format('Y-m-d'), $resubmitOn === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->bindValue(5, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $id, \PDO::PARAM_INT);
        $stmt->bindValue(7, $von->value);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function setzeKostenstelle(int $id, ?int $costCenterId): void
    {
        $stmt = $this->pdo->prepare('UPDATE document SET cost_center_id = ? WHERE id = ?');
        $stmt->bindValue(1, $costCenterId, $costCenterId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return array{0: list<string>, 1: list<int|string>}
     */
    private static function ansicht(InboxAnsicht $ansicht, \DateTimeImmutable $heute): array
    {
        return match ($ansicht) {
            InboxAnsicht::Offen => [
                ['(d.status = ? OR (d.status = ? AND d.resubmit_on <= ?))'],
                [DocumentStatus::Eingegangen->value, DocumentStatus::Wiedervorlage->value, $heute->format('Y-m-d')],
            ],
            InboxAnsicht::Wiedervorlage => [['d.status = ?'], [DocumentStatus::Wiedervorlage->value]],
            InboxAnsicht::Abgelehnt => [['d.status = ?'], [DocumentStatus::Abgelehnt->value]],
            InboxAnsicht::Angenommen => [
                ['d.status IN (' . implode(', ', array_fill(0, count(InboxAnsicht::angenommeneStatus()), '?')) . ')'],
                array_map(static fn(DocumentStatus $s): string => $s->value, InboxAnsicht::angenommeneStatus()),
            ],
            InboxAnsicht::Alle => [[], []],
        };
    }
}
