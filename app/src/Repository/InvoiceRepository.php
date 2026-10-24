<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Differenzgrund;
use App\Domain\DocumentStatus;
use App\Domain\ExportRunStatus;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceRecord;
use App\Domain\InvoiceStructure;
use App\Domain\OffenerBelegRecord;
use App\Domain\PaymentStatus;
use App\Domain\Zahlungsweg;
use App\Domain\Zugriffsbereich;
use App\Service\Export\ExportBeleg;
use App\Service\Export\ExportFilter;

/**
 * The `invoice` table (migrations/019_invoice.sql, issue #37/M6-3, lock columns
 * migrations/020_invoice_lock.sql, issue #38/M6-4,
 * docs/spec/02-datenmodell.md "Fachdaten"). SQL only - encrypting and the
 * rules live in App\Service\Invoice\Pruefung.
 *
 * Everything that comes back is ciphertext plus plaintext structure
 * (App\Domain\InvoiceRecord); nothing here can read an amount.
 *
 * A locked receipt (issue #38/M6-4, `locked_at` set) is not changed by
 * update() and setzeGeprueft(): the condition sits in the SQL, so no caller -
 * now or in a later milestone - can alter it by mistake. These are silent
 * no-ops; the loud refusal is the service's (App\Service\Invoice\Pruefung
 * checks the document's status under a row lock).
 */
final readonly class InvoiceRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** The migration that introduced Zahlungsweg and payment status (037_allocation.sql). */
    private const int MIGRATION_ABGLEICH = 37;

    public function __construct(private \PDO $pdo)
    {
    }

    public function findByDocument(int $documentId): ?InvoiceRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice WHERE document_id = ?');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch();

        return $row === false ? null : InvoiceRecord::fromRow($row);
    }

    /**
     * @param bool $sperren row lock until the caller's transaction ends -
     *        the matching (M10-1) checks sums under it
     */
    public function find(int $id, bool $sperren = false): ?InvoiceRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice WHERE id = ?' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : InvoiceRecord::fromRow($row);
    }

    /**
     * The status of a receipt's document, read with a shared lock - the
     * matching (M10-1) refuses a rejected document. Taken before the
     * receipt's own row lock, in the order the review page and the
     * duplicate check lock (document, then receipt).
     */
    public function dokumentStatus(int $documentId): ?DocumentStatus
    {
        $stmt = $this->pdo->prepare('SELECT status FROM document WHERE id = ? LOCK IN SHARE MODE');
        $stmt->execute([$documentId]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : DocumentStatus::from((string) $status);
    }

    /** Whether any booking is allocated to this receipt (M10-1). */
    public function hatZuordnungen(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM allocation WHERE invoice_id = ?)');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Writes the derived payment side (M10-1, issue #65): the status
     * App\Domain\PaymentStatus::ableiten() produced and the reason of a
     * closed difference. Deliberately not bound to `locked_at`: a locked
     * receipt may still be paid - the lock covers what the receipt says.
     * `updated_at`/`updated_by` stay: nothing of the receipt changed. Only
     * App\Service\Bank\Zuordnung calls this.
     */
    public function setzeZahlungsstatus(int $id, PaymentStatus $status, ?Differenzgrund $differenz): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET payment_status = ?, difference_reason = ? WHERE id = ?');
        $stmt->bindValue(1, $status->value);
        $stmt->bindValue(2, $differenz?->value);
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction.
     */
    public function insert(
        int $documentId,
        InvoiceStructure $struktur,
        string $dekSealed,
        ?string $numberBi,
        ?int $userId,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO invoice (document_id, doc_type, direction, supplier_id, invoice_date, due_date, service_from, service_to,
                                  category_id, cost_center_id, payment_kind, payment_status, dek_sealed, data_enc, number_bi,
                                  created_by, created_at, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?)",
        );
        $stmt->bindValue(1, $documentId, \PDO::PARAM_INT);
        $n = self::bindStruktur($stmt, 2, $struktur);
        // A new receipt has no allocations: its status is what the
        // Zahlungsweg alone implies (App\Domain\PaymentStatus::ableiten()).
        $stmt->bindValue($n++, PaymentStatus::ableiten($struktur->paymentKind, 0, 0, null)->value);
        $stmt->bindValue($n++, $dekSealed, \PDO::PARAM_LOB);
        self::bindNullable($stmt, $n++, $numberBi, \PDO::PARAM_LOB);
        self::bindNullable($stmt, $n++, $userId, \PDO::PARAM_INT);
        $stmt->bindValue($n++, $now->format(self::FORMAT));
        self::bindNullable($stmt, $n++, $userId, \PDO::PARAM_INT);
        $stmt->bindValue($n, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setData(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function update(
        int $id,
        InvoiceStructure $struktur,
        string $dataEnc,
        ?string $numberBi,
        ?int $userId,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE invoice SET doc_type = ?, direction = ?, supplier_id = ?, invoice_date = ?, due_date = ?, service_from = ?,
                                service_to = ?, category_id = ?, cost_center_id = ?, payment_kind = ?, data_enc = ?, number_bi = ?,
                                updated_by = ?, updated_at = ?
             WHERE id = ? AND locked_at IS NULL',
        );
        $n = self::bindStruktur($stmt, 1, $struktur);
        $stmt->bindValue($n++, $dataEnc, \PDO::PARAM_LOB);
        self::bindNullable($stmt, $n++, $numberBi, \PDO::PARAM_LOB);
        self::bindNullable($stmt, $n++, $userId, \PDO::PARAM_INT);
        $stmt->bindValue($n++, $now->format(self::FORMAT));
        $stmt->bindValue($n, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Merging suppliers (issue #39/M6-5): every receipt of one supplier
     * that is not locked moves to the other. A locked one keeps its
     * supplier - the condition is in the SQL like in update(); the merged
     * supplier's `merged_into` leads to the new one. Returns how many moved.
     */
    public function haengeLieferantUm(int $von, int $nach, ?int $userId, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET supplier_id = ?, updated_by = ?, updated_at = ? WHERE supplier_id = ? AND locked_at IS NULL');
        $stmt->bindValue(1, $nach, \PDO::PARAM_INT);
        self::bindNullable($stmt, 2, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->bindValue(4, $von, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * The receipts of a supplier, split into those a merge would move and
     * the locked ones it leaves where they are.
     *
     * @return array{offen: int, festgeschrieben: int}
     */
    public function anzahlNachLieferant(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(locked_at IS NULL), 0) AS offen, COALESCE(SUM(locked_at IS NOT NULL), 0) AS festgeschrieben
             FROM invoice WHERE supplier_id = ?',
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch();

        return ['offen' => (int) ($row['offen'] ?? 0), 'festgeschrieben' => (int) ($row['festgeschrieben'] ?? 0)];
    }

    /**
     * The categories of a supplier's newest checked receipts, newest first
     * (M7-7, issue #49: the learnt default, App\Service\Invoice\
     * Kategorisierung) - checked or locked, also those still pointing at a
     * supplier merged into this one, only within the reader's scope. NULL
     * for a receipt without category.
     *
     * @return list<?int>
     */
    public function letzteGeprueftenKategorien(int $supplierId, int $anzahl, Zugriffsbereich $bereich): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id');
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT i.category_id FROM invoice i JOIN supplier s ON s.id = i.supplier_id
             WHERE (i.supplier_id = ? OR s.merged_into = ?) AND i.checked_at IS NOT NULL AND %s
             ORDER BY i.checked_at DESC, i.id DESC LIMIT %d',
            $scope,
            max(1, $anzahl),
        ));
        $stmt->execute([$supplierId, $supplierId, ...$parameter]);

        return array_map(
            static fn(mixed $id): ?int => $id === null ? null : (int) $id,
            $stmt->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    /**
     * The checked receipts (`geprueft`/`festgeschrieben`) of a supplier and
     * direction, also those still pointing at a supplier merged into it -
     * what series detection (issue #57/M8-1, App\Service\Invoice\Serien)
     * looks at. Not scoped: a series is about the supplier, and its pages
     * are only open to accounts without a scope. Only the $anzahl newest
     * (by receipt date), oldest first - each one is decrypted, and a
     * supplier's history should not make a request long.
     *
     * @return list<InvoiceRecord>
     */
    public function geprueftFuerLieferant(int $supplierId, InvoiceDirection $richtung, int $anzahl = 120): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT i.* FROM invoice i JOIN supplier s ON s.id = i.supplier_id JOIN document d ON d.id = i.document_id
             WHERE (i.supplier_id = ? OR s.merged_into = ?) AND i.direction = ? AND i.checked_at IS NOT NULL AND d.status IN (?, ?)
             ORDER BY i.invoice_date DESC, i.id DESC LIMIT %d',
            max(1, $anzahl),
        ));
        $stmt->execute([$supplierId, $supplierId, $richtung->value, DocumentStatus::Geprueft->value, DocumentStatus::Festgeschrieben->value]);

        return array_reverse(array_map(InvoiceRecord::fromRow(...), $stmt->fetchAll()));
    }

    /**
     * Receipts by id, in date order - the receipts of a series (issue
     * #57/M8-1), whatever their state now.
     *
     * @param list<int> $ids
     *
     * @return list<InvoiceRecord>
     */
    public function nachIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM invoice WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY invoice_date, id',
        );
        $stmt->execute(array_values($ids));

        return array_map(InvoiceRecord::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The receipts still waiting for money (M10-5, issue #69): Zahlungsweg
     * $weg, a payment status out of $status, the document not rejected,
     * within $bereich (receipt date and cost center). Due date first (none
     * last), then receipt date. The submission is joined for the
     * reimbursement list; `altbestand` says the receipt is older than
     * Migration 037 - every receipt then became `konto`/`offen`, also cash
     * receipts from the archive (E-27, E-37). No schema_version row for 37
     * (cannot happen after migrate) counts as "not older".
     *
     * @param list<PaymentStatus> $status
     *
     * @return list<OffenerBelegRecord>
     */
    public function offen(Zahlungsweg $weg, array $status, Zugriffsbereich $bereich): array
    {
        if ($status === []) {
            return [];
        }
        [$scope, $parameter] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id');
        $stmt = $this->pdo->prepare(
            'SELECT i.*, s.id AS submission_id, s.dek_sealed AS submission_dek_sealed, s.payload_enc AS submission_payload_enc,
                    COALESCE(i.created_at < (SELECT sv.applied_at FROM schema_version sv WHERE sv.version = ?), 0) AS altbestand
             FROM invoice i JOIN document d ON d.id = i.document_id
             LEFT JOIN submission s ON s.id = d.submission_id
             WHERE i.payment_kind = ? AND i.payment_status IN (' . implode(', ', array_fill(0, count($status), '?')) . ')
               AND d.status <> ? AND ' . $scope . '
             ORDER BY i.due_date IS NULL, i.due_date, i.invoice_date, i.id',
        );
        $stmt->execute([
            self::MIGRATION_ABGLEICH,
            $weg->value,
            ...array_map(static fn(PaymentStatus $s): string => $s->value, $status),
            DocumentStatus::Abgelehnt->value,
            ...$parameter,
        ]);

        return array_map(OffenerBelegRecord::fromRow(...), $stmt->fetchAll());
    }

    /** Who marked the receipt as checked, and when ("Geprüft, nächster"). */
    public function setzeGeprueft(int $id, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET checked_by = ?, checked_at = ? WHERE id = ? AND locked_at IS NULL');
        self::bindNullable($stmt, 1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $now->format(self::FORMAT));
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Takes back "checked" from a receipt that is not locked - a checked
     * receipt discarded as a duplicate (issue #40/M6-6) is `abgelehnt`, and
     * `checked_at` stays set only for `geprueft`/`festgeschrieben`.
     */
    public function setzeUngeprueft(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET checked_by = NULL, checked_at = NULL WHERE id = ? AND locked_at IS NULL');
        $stmt->bindValue(1, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Who locked the receipt, and when (issue #38/M6-4). Only a receipt that
     * is not locked yet takes it.
     */
    public function setzeFestgeschrieben(int $id, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET locked_by = ?, locked_at = ? WHERE id = ? AND locked_at IS NULL');
        self::bindNullable($stmt, 1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $now->format(self::FORMAT));
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Lifts the lock. The receipt is no longer "checked" either - it goes
     * back to the review (who lifted it, and why, is in the audit log).
     */
    public function hebeFestschreibungAuf(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoice SET locked_by = NULL, locked_at = NULL, checked_by = NULL, checked_at = NULL WHERE id = ?');
        $stmt->bindValue(1, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The receipts of the ZIP export (issue #76/M12-2): period on the receipt
     * date, the document's status, category, cost center and supplier
     * (0 = none assigned), always narrowed to what $bereich allows. In a
     * fixed order - receipt date, then id - so the same filter gives the
     * same file names on every run (App\Service\Export\PfadVergabe).
     *
     * "Nur noch nicht exportierte" (issue #179/M12-4) leaves out every
     * document a confirmed export run holds. With $lauf the list is that
     * run's documents instead - so downloading a run again gives the same
     * receipts, even after another run confirmed some of them.
     *
     * @param int|null $lauf an `export_run` id
     *
     * @return list<ExportBeleg>
     */
    public function exportListe(ExportFilter $filter, Zugriffsbereich $bereich, ?int $lauf = null): array
    {
        $status = $filter->status->statusse();
        $bedingungen = [
            'i.invoice_date >= ?',
            'i.invoice_date <= ?',
            'd.status IN (' . implode(', ', array_fill(0, count($status), '?')) . ')',
        ];
        $parameter = [
            $filter->von->format('Y-m-d'),
            $filter->bis->format('Y-m-d'),
            ...array_map(static fn(DocumentStatus $s): string => $s->value, $status),
        ];

        foreach (['i.category_id' => $filter->kategorieId, 'i.cost_center_id' => $filter->kostenstelleId, 'i.supplier_id' => $filter->lieferantId] as $spalte => $id) {
            if ($id === 0) {
                $bedingungen[] = $spalte . ' IS NULL';
            } elseif ($id !== null) {
                $bedingungen[] = $spalte . ' = ?';
                $parameter[] = $id;
            }
        }

        if ($lauf !== null) {
            $bedingungen[] = 'd.id IN (SELECT erd.document_id FROM export_run_document erd WHERE erd.export_run_id = ?)';
            $parameter[] = $lauf;
        } elseif ($filter->nurNeue) {
            $bedingungen[] = 'NOT EXISTS (SELECT 1 FROM export_run_document erd JOIN export_run r ON r.id = erd.export_run_id
                                          WHERE erd.document_id = d.id AND r.status = ?)';
            $parameter[] = ExportRunStatus::Bestaetigt->value;
        }

        [$scope, $scopeParameter] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id');
        $bedingungen[] = $scope;
        array_push($parameter, ...$scopeParameter);

        $stmt = $this->pdo->prepare(
            'SELECT i.*, d.status AS document_status, d.pdf_blob_id AS document_pdf_blob_id,
                    d.original_blob_ids AS document_original_blob_ids
             FROM invoice i JOIN document d ON d.id = i.document_id
             WHERE ' . implode(' AND ', $bedingungen) . '
             ORDER BY i.invoice_date, i.id',
        );
        $stmt->execute($parameter);

        return array_map(ExportBeleg::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The suppliers the export page may offer as a filter (issue #76/M12-2):
     * those of receipts within $bereich that are not rejected - a scoped
     * role must not learn the names of suppliers it never sees a receipt of.
     *
     * @return list<int>
     */
    public function lieferantenImBereich(Zugriffsbereich $bereich): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT i.supplier_id FROM invoice i JOIN document d ON d.id = i.document_id
             WHERE i.supplier_id IS NOT NULL AND d.status <> ? AND ' . $scope,
        );
        $stmt->execute([DocumentStatus::Abgelehnt->value, ...$parameter]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int the next free parameter position
     */
    private static function bindStruktur(\PDOStatement $stmt, int $n, InvoiceStructure $s): int
    {
        $datum = static fn(?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d');

        $stmt->bindValue($n++, $s->docType->value);
        $stmt->bindValue($n++, $s->direction->value);
        self::bindNullable($stmt, $n++, $s->supplierId, \PDO::PARAM_INT);
        $stmt->bindValue($n++, $s->invoiceDate->format('Y-m-d'));
        self::bindNullable($stmt, $n++, $datum($s->dueDate), \PDO::PARAM_STR);
        self::bindNullable($stmt, $n++, $datum($s->serviceFrom), \PDO::PARAM_STR);
        self::bindNullable($stmt, $n++, $datum($s->serviceTo), \PDO::PARAM_STR);
        self::bindNullable($stmt, $n++, $s->categoryId, \PDO::PARAM_INT);
        self::bindNullable($stmt, $n++, $s->costCenterId, \PDO::PARAM_INT);
        $stmt->bindValue($n++, $s->paymentKind->value);

        return $n;
    }

    private static function bindNullable(\PDOStatement $stmt, int $n, int|string|null $wert, int $typ): void
    {
        $stmt->bindValue($n, $wert, $wert === null ? \PDO::PARAM_NULL : $typ);
    }
}
