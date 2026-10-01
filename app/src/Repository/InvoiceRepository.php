<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\InvoiceRecord;
use App\Domain\InvoiceStructure;

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
                                  category_id, cost_center_id, dek_sealed, data_enc, number_bi, created_by, created_at, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?)",
        );
        $stmt->bindValue(1, $documentId, \PDO::PARAM_INT);
        $n = self::bindStruktur($stmt, 2, $struktur);
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
                                service_to = ?, category_id = ?, cost_center_id = ?, data_enc = ?, number_bi = ?,
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

        return $n;
    }

    private static function bindNullable(\PDOStatement $stmt, int $n, int|string|null $wert, int $typ): void
    {
        $stmt->bindValue($n, $wert, $wert === null ? \PDO::PARAM_NULL : $typ);
    }
}
