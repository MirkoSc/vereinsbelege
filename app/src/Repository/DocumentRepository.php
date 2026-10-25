<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ArtifactKind;
use App\Domain\Document;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\InboxItem;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
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
 * status changes and the cost center; the review queue (issue #37/M6-3);
 * the content index of duplicate detection (issue #40/M6-6).
 * Every inbox read takes the viewer's
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

    /** ocrNachholbar(): see there. */
    private const string OCR_NACHHOLBAR =
        'd.ocr_status = ? AND d.status NOT IN (?, ?) AND d.source <> ?
         AND NOT EXISTS (SELECT 1 FROM job j WHERE j.typ = ? AND j.ref_type = ? AND j.ref_id = d.id AND j.status IN (?, ?, ?))
         AND NOT EXISTS (SELECT 1 FROM document_artifact a WHERE a.document_id = d.id AND a.producer = ? AND a.kind = ?)';

    /**
     * bestandOcrNachholbar(): like OCR_NACHHOLBAR, but archive documents
     * only and locked ones included (E-55) - OCR adds a derived text layer,
     * it never changes the receipt or its files. An `ocr` job that failed
     * since the backfill started ($seit) counts as done for this run - a
     * broken PDF is not handed over again and again (issue #88 review).
     */
    private const string BESTAND_OCR =
        'd.source = ? AND d.ocr_status = ? AND d.status <> ? AND d.id >= ? AND (? = 0 OR d.id <= ?)
         AND NOT EXISTS (SELECT 1 FROM job j WHERE j.typ = ? AND j.ref_type = ? AND j.ref_id = d.id AND j.status IN (?, ?, ?))
         AND NOT EXISTS (SELECT 1 FROM job f WHERE f.typ = ? AND f.ref_type = ? AND f.ref_id = d.id AND f.status = ? AND f.created_at >= ?)
         AND NOT EXISTS (SELECT 1 FROM document_artifact a WHERE a.document_id = d.id AND a.producer = ? AND a.kind = ?)';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<int> $originalBlobIds page order, as uploaded
     * @param int|null $createdBy the account that captured it internally
     *        (issue #28/M4-6); null for the public submission
     * @param list<int|null> $processedBlobIds parallel to $originalBlobIds,
     *        the scanner's processed version of each page (issue #34/M5-4);
     *        stored as NULL when no entry is set
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
        ?int $createdBy = null,
        array $processedBlobIds = [],
    ): int {
        $processed = array_filter($processedBlobIds, is_int(...)) === [] ? null : json_encode(array_values($processedBlobIds), JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO document (source, submission_id, original_blob_ids, processed_blob_ids, status, ocr_status, dek_sealed, created_at, cost_center_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $source->value);
        $stmt->bindValue(2, $submissionId, $submissionId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, json_encode($originalBlobIds, JSON_THROW_ON_ERROR));
        $stmt->bindValue(4, $processed, $processed === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(5, $status->value);
        $stmt->bindValue(6, $ocrStatus->value);
        $stmt->bindValue(7, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(8, $now->format(self::FORMAT));
        $stmt->bindValue(9, $costCenterId, $costCenterId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(10, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
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
     * one that won. A locked document (issue #38/M6-4) takes none: its
     * documents are fixed.
     */
    public function setzePdfBlob(int $id, int $blobId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE document SET pdf_blob_id = ? WHERE id = ? AND pdf_blob_id IS NULL AND status <> ?');
        $stmt->bindValue(1, $blobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->bindValue(3, DocumentStatus::Festgeschrieben->value);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Stores the content index of the original files (issue #40/M6-6, the
     * `detect_duplicate` job), once: a document that has one keeps it. A
     * locked document takes it too - the index is derived from files that
     * never change, it is not part of the receipt (docs/spec/01-sicherheit.md
     * section 7), and only so does a receipt submitted again long after it
     * was locked get noticed. Returns whether this call wrote it.
     */
    public function setzeContentBi(int $id, string $contentBi): bool
    {
        $stmt = $this->pdo->prepare('UPDATE document SET content_bi = ? WHERE id = ? AND content_bi IS NULL');
        $stmt->bindValue(1, $contentBi, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * The text recognition status (docs/spec/02-datenmodell.md, issue
     * #45/M7-3: set by the `extract_text` job, App\Service\Document\
     * Texterkennung). Derived from files that never change, like
     * `content_bi` - so a locked document takes it as well.
     */
    public function setzeOcrStatus(int $id, OcrStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE document SET ocr_status = ? WHERE id = ?');
        $stmt->bindValue(1, $status->value);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Documents whose OCR is to be caught up (docs/spec/07-worker.md section
     * 6 "OCR nachholen", issue #53): page images needed (`ocr_status`
     * `uebersprungen`), not fixed or rejected, not from the archive import
     * (E-55: bestandOcrNachholbar()), and no `ocr` job that is pending or
     * done and no taken-over OCR text of the worker - a failed or skipped
     * one (the worker fell out) may be repeated.
     * Oldest first, ids only.
     *
     * @return list<int>
     */
    public function ocrNachholbar(int $limit, int $nachId = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id FROM document d WHERE ' . self::OCR_NACHHOLBAR . ' AND d.id > ? ORDER BY d.id LIMIT ' . max(1, $limit),
        );
        $stmt->execute([...self::ocrNachholbarParameter(), $nachId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Whether this one document is eligible for "OCR nachholen" (the same
     * rule as ocrNachholbar()) - the inbox's button per receipt (issue #248).
     */
    public function istOcrNachholbar(int $documentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM document d WHERE ' . self::OCR_NACHHOLBAR . ' AND d.id = ?');
        $stmt->execute([...self::ocrNachholbarParameter(), $documentId]);

        return $stmt->fetchColumn() !== false;
    }

    public function zaehleOcrNachholbar(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document d WHERE ' . self::OCR_NACHHOLBAR);
        $stmt->execute(self::ocrNachholbarParameter());

        return (int) $stmt->fetchColumn();
    }

    /**
     * Archive documents whose OCR is to be caught up by the throttled
     * backfill (docs/spec/07-worker.md section 6 "Stand #88", E-55): source
     * `archiv`, `ocr_status` `uebersprungen`, not rejected - locked ones
     * included -, id within $von..$bis ($bis 0 = no upper end) and above
     * $nachId, no `ocr` job pending or done and no taken-over worker text.
     * Oldest first, ids only.
     *
     * @return list<int>
     */
    public function bestandOcrNachholbar(int $von, int $bis, \DateTimeImmutable $seit, int $nachId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id FROM document d WHERE ' . self::BESTAND_OCR . ' AND d.id > ? ORDER BY d.id LIMIT ' . max(1, $limit),
        );
        $stmt->execute([...self::bestandOcrParameter($von, $bis, $seit), $nachId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Progress of the backfill over archive documents $von..$bis ($bis 0 =
     * no upper end, rejected ones left out): all of them, those with a text
     * layer (`ocr_status` `fertig`), those whose own text detection has not
     * decided yet (`keine`/`ausstehend` with an `extract_text` job pending), those with an `ocr` job at the
     * worker right now, those whose OCR failed since $seit (without a text
     * layer), and those still to be caught up (bestandOcrNachholbar()).
     *
     * @return array{gesamt: int, mitText: int, inErkennung: int, beimWorker: int, fehlgeschlagen: int, offen: int}
     */
    public function bestandOcrStand(int $von, int $bis, \DateTimeImmutable $seit): array
    {
        $bereich = 'd.source = ? AND d.status <> ? AND d.id >= ? AND (? = 0 OR d.id <= ?)';
        $bereichParameter = [DocumentSource::Archiv->value, DocumentStatus::Abgelehnt->value, $von, $bis, $bis];
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS gesamt, COALESCE(SUM(d.ocr_status = ?), 0) AS mit_text,
                    COALESCE(SUM(d.ocr_status IN (?, ?) AND EXISTS (SELECT 1 FROM job t WHERE t.typ = ? AND t.ref_type = ? AND t.ref_id = d.id AND t.status IN (?, ?))), 0) AS in_erkennung,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM job j WHERE j.typ = ? AND j.ref_type = ? AND j.ref_id = d.id AND j.executor = ? AND j.status IN (?, ?))), 0) AS beim_worker,
                    COALESCE(SUM(d.ocr_status <> ? AND EXISTS (SELECT 1 FROM job f WHERE f.typ = ? AND f.ref_type = ? AND f.ref_id = d.id AND f.status = ? AND f.created_at >= ?)), 0) AS fehlgeschlagen
             FROM document d WHERE ' . $bereich,
        );
        $stmt->execute([
            OcrStatus::Fertig->value,
            OcrStatus::Keine->value,
            OcrStatus::Ausstehend->value,
            // App\Service\Document\Texterkennung::JOB_TYP
            'extract_text',
            'document',
            JobStatus::Offen->value,
            JobStatus::Laeuft->value,
            'ocr',
            'document',
            JobExecutor::Worker->value,
            JobStatus::Offen->value,
            JobStatus::Laeuft->value,
            OcrStatus::Fertig->value,
            'ocr',
            'document',
            JobStatus::Fehler->value,
            $seit->format(self::FORMAT),
            ...$bereichParameter,
        ]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        $offen = $this->pdo->prepare('SELECT COUNT(*) FROM document d WHERE ' . self::BESTAND_OCR);
        $offen->execute(self::bestandOcrParameter($von, $bis, $seit));

        return [
            'gesamt' => (int) ($zeile['gesamt'] ?? 0),
            'mitText' => (int) ($zeile['mit_text'] ?? 0),
            'inErkennung' => (int) ($zeile['in_erkennung'] ?? 0),
            'beimWorker' => (int) ($zeile['beim_worker'] ?? 0),
            'fehlgeschlagen' => (int) ($zeile['fehlgeschlagen'] ?? 0),
            'offen' => (int) $offen->fetchColumn(),
        ];
    }

    /**
     * @return list<int|string>
     */
    private static function bestandOcrParameter(int $von, int $bis, \DateTimeImmutable $seit): array
    {
        return [
            DocumentSource::Archiv->value,
            OcrStatus::Uebersprungen->value,
            DocumentStatus::Abgelehnt->value,
            $von,
            $bis,
            $bis,
            'ocr',
            'document',
            JobStatus::Offen->value,
            JobStatus::Laeuft->value,
            JobStatus::Fertig->value,
            'ocr',
            'document',
            JobStatus::Fehler->value,
            $seit->format(self::FORMAT),
            JobExecutor::Worker->value,
            ArtifactKind::Text->value,
        ];
    }

    /**
     * @return list<string>
     */
    private static function ocrNachholbarParameter(): array
    {
        return [
            OcrStatus::Uebersprungen->value,
            DocumentStatus::Festgeschrieben->value,
            DocumentStatus::Abgelehnt->value,
            // Archive documents are caught up by the throttled backfill
            // (bestandOcrNachholbar(), E-55), not in 50s at a click.
            DocumentSource::Archiv->value,
            'ocr',
            'document',
            JobStatus::Offen->value,
            JobStatus::Laeuft->value,
            JobStatus::Fertig->value,
            // Finished jobs are cleaned up after a while; the worker's OCR
            // text that was taken over stays as the proof. A discarded or
            // defective one (`!text`) does not block a new attempt.
            JobExecutor::Worker->value,
            ArtifactKind::Text->value,
        ];
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
     * How many documents the inbox view "Offen" shows (dashboard, issue
     * #72): new ones and every Wiedervorlage whose date has come, within
     * $bereich - the same conditions as inbox(), counted in SQL.
     */
    public function anzahlPosteingangOffen(Zugriffsbereich $bereich, \DateTimeImmutable $heute): int
    {
        [$bedingungen, $parameter] = self::ansicht(InboxAnsicht::Offen, $heute);
        [$scope, $scopeParameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');
        $bedingungen[] = $scope;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document d WHERE ' . implode(' AND ', $bedingungen));
        $stmt->execute([...$parameter, ...$scopeParameter]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many documents are in one of $status within $bereich (dashboard,
     * issue #72: the review queue is DocumentStatus::pruefbare()).
     *
     * @param list<DocumentStatus> $status
     */
    public function anzahlImStatus(array $status, Zugriffsbereich $bereich): int
    {
        if ($status === []) {
            return 0;
        }
        [$bedingung, $parameter] = self::imStatusBedingung($status, $bereich);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document d WHERE ' . $bedingung);
        $stmt->execute($parameter);

        return (int) $stmt->fetchColumn();
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
     * Inbox rows by id, newest id first, only those within $bereich - the
     * full-text search (issue #94) shows its hits through this, so a hit
     * outside the viewer's scope never reaches the page, whatever the
     * session index holds.
     *
     * @param list<int> $ids
     *
     * @return list<InboxItem>
     */
    public function inboxItems(array $ids, Zugriffsbereich $bereich): array
    {
        if ($ids === []) {
            return [];
        }
        [$scope, $parameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');
        $stmt = $this->pdo->prepare(
            self::INBOX_SELECT . ' WHERE d.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') AND ' . $scope
            . ' ORDER BY d.id DESC',
        );
        $stmt->execute([...array_map(intval(...), $ids), ...$parameter]);

        return array_map(InboxItem::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The review queue (issue #37/M6-3): every document the review page may
     * capture (App\Domain\DocumentStatus::pruefbare()), oldest first - a
     * queue is worked off in the order it came in. Narrowed to $bereich
     * like the inbox.
     *
     * @return list<InboxItem>
     */
    public function pruefListe(Zugriffsbereich $bereich, int $limit): array
    {
        return $this->imStatus(DocumentStatus::pruefbare(), $bereich, $limit);
    }

    /**
     * The receipts that are checked and wait to be locked (issue #38/M6-4),
     * oldest first, narrowed to $bereich like the queue.
     *
     * @return list<InboxItem>
     */
    public function geprueftListe(Zugriffsbereich $bereich, int $limit): array
    {
        return $this->imStatus([DocumentStatus::Geprueft], $bereich, $limit);
    }

    /**
     * @param list<DocumentStatus> $status
     *
     * @return list<InboxItem>
     */
    private function imStatus(array $status, Zugriffsbereich $bereich, int $limit): array
    {
        [$bedingung, $parameter] = self::imStatusBedingung($status, $bereich);
        $stmt = $this->pdo->prepare(
            self::INBOX_SELECT . ' WHERE ' . $bedingung . ' ORDER BY d.created_at, d.id LIMIT ' . max(1, $limit),
        );
        $stmt->execute($parameter);

        return array_map(InboxItem::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The WHERE of the status lists and their counts (the dashboard links
     * to the list it counts, so both use this one condition).
     *
     * @param non-empty-list<DocumentStatus> $status
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function imStatusBedingung(array $status, Zugriffsbereich $bereich): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('d.created_at', 'd.cost_center_id');

        return [
            'd.status IN (' . implode(', ', array_fill(0, count($status), '?')) . ') AND ' . $scope,
            [...array_map(static fn(DocumentStatus $s): string => $s->value, $status), ...$parameter],
        ];
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

    /**
     * Inside a transaction: locks the row if the document is still in
     * $status, so nobody changes it before the commit (issue #37/M6-3 - a
     * review saved while somebody else just marked the document as checked
     * must not overwrite it). False when it is not in $status any more.
     */
    public function sperreImStatus(int $id, DocumentStatus $status): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM document WHERE id = ? AND status = ? FOR UPDATE');
        $stmt->execute([$id, $status->value]);

        return $stmt->fetch() !== false;
    }

    /**
     * Inside a transaction: locks several documents, smallest id first - two
     * transactions locking overlapping sets this way wait for each other
     * instead of deadlocking (issue #40/M6-6: a document and every other
     * side of its duplicate pairs).
     *
     * @param list<int> $ids
     */
    public function sperreAlle(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM document WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE',
        );
        $stmt->execute($ids);
        $stmt->fetchAll();
    }

    /**
     * A locked document (issue #38/M6-4) keeps its cost center: the
     * condition is in the SQL, the services refuse loudly before.
     */
    public function setzeKostenstelle(int $id, ?int $costCenterId): void
    {
        $stmt = $this->pdo->prepare('UPDATE document SET cost_center_id = ? WHERE id = ? AND status <> ?');
        $stmt->bindValue(1, $costCenterId, $costCenterId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->bindValue(3, DocumentStatus::Festgeschrieben->value);
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
