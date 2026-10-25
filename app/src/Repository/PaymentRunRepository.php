<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DocumentStatus;
use App\Domain\PaymentStatus;
use App\Domain\ZahllaufRecord;
use App\Domain\ZahllaufStatus;
use App\Domain\Zugriffsbereich;

/**
 * The tables `payment_run` and `payment_run_item` (issue #85,
 * migrations/042_payment_run.sql, E-42). SQL only - building, encrypting
 * and the rules live in App\Service\Bank\Zahllaeufe.
 *
 * A run is visible within a scope only if EVERY receipt it holds lies in
 * that scope: its file names all payees and amounts.
 */
final readonly class PaymentRunRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** MariaDB's error for a duplicate key (SQLSTATE 23000). */
    private const int DUPLICATE = 1062;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * First step of a new run: everything but the ciphertext, whose AAD
     * needs the id. The caller writes setCiphertext() in the same
     * transaction.
     */
    public function insert(int $bankAccountId, \DateTimeImmutable $executionDate, string $dekSealed, ?int $userId, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO payment_run (bank_account_id, status, execution_date, dek_sealed, data_enc, created_by, created_at)
             VALUES (?, ?, ?, ?, '', ?, ?)",
        );
        $stmt->bindValue(1, $bankAccountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, ZahllaufStatus::Angewiesen->value);
        $stmt->bindValue(3, $executionDate->format('Y-m-d'));
        $stmt->bindValue(4, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setCiphertext(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE payment_run SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Adds a receipt to a run and reserves it. False when the receipt is in
     * another run that is still `angewiesen` (the UNIQUE key on
     * `active_invoice_id`) - nothing is written then.
     */
    public function insertItem(int $runId, int $invoiceId): bool
    {
        $stmt = $this->pdo->prepare('INSERT INTO payment_run_item (payment_run_id, invoice_id, active_invoice_id) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$runId, $invoiceId, $invoiceId]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * The documents of $invoiceIds - to lock them first with
     * DocumentRepository::sperreAlle(), in the order App\Service\Bank\
     * Zuordnung uses (documents -> receipts).
     *
     * @param list<int> $invoiceIds
     *
     * @return list<int>
     */
    public function dokumente(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf('SELECT document_id FROM invoice WHERE id IN (%s)', implode(', ', array_fill(0, count($invoiceIds), '?'))));
        $stmt->execute($invoiceIds);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Locks the receipts of $invoiceIds in ascending order (after their
     * documents).
     *
     * @param list<int> $invoiceIds
     */
    public function sperreBelege(array $invoiceIds): void
    {
        if ($invoiceIds === []) {
            return;
        }
        $ids = array_values(array_unique($invoiceIds));
        sort($ids);
        $stmt = $this->pdo->prepare(sprintf('SELECT id FROM invoice WHERE id IN (%s) ORDER BY id FOR UPDATE', implode(', ', array_fill(0, count($ids), '?'))));
        $stmt->execute($ids);
        $stmt->fetchAll();
    }

    /**
     * Receipts of $invoiceIds that a run still holds (`angewiesen`):
     * invoice id => run id.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, int>
     */
    public function angewieseneBelege(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT active_invoice_id, payment_run_id FROM payment_run_item WHERE active_invoice_id IN (%s)',
            implode(', ', array_fill(0, count($invoiceIds), '?')),
        ));
        $stmt->execute($invoiceIds);
        $ergebnis = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$beleg, $lauf]) {
            $ergebnis[(int) $beleg] = (int) $lauf;
        }

        return $ergebnis;
    }

    /**
     * The document status of each receipt in $invoiceIds: invoice id =>
     * status. Only checked receipts are paid out.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, DocumentStatus>
     */
    public function dokumentStatus(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT i.id, d.status FROM invoice i JOIN document d ON d.id = i.document_id WHERE i.id IN (%s)',
            implode(', ', array_fill(0, count($invoiceIds), '?')),
        ));
        $stmt->execute($invoiceIds);
        $ergebnis = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $status]) {
            $ergebnis[(int) $id] = DocumentStatus::from((string) $status);
        }

        return $ergebnis;
    }

    public function find(int $id, Zugriffsbereich $bereich, bool $sperren = false): ?ZahllaufRecord
    {
        [$sql, $parameter] = $this->auswahl($bereich);
        $stmt = $this->pdo->prepare($sql . ' AND r.id = ?' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([...$parameter, $id]);
        $row = $stmt->fetch();

        return $row === false ? null : ZahllaufRecord::fromRow($row);
    }

    /**
     * The newest runs first.
     *
     * @return list<ZahllaufRecord>
     */
    public function liste(Zugriffsbereich $bereich, int $limit = 100): array
    {
        [$sql, $parameter] = $this->auswahl($bereich);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($parameter);

        return array_map(ZahllaufRecord::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Each receipt of the run with its payment status (for "ausgeglichen"
     * per transfer).
     *
     * @return array<int, PaymentStatus> invoice id => status
     */
    public function belegeDesLaufs(int $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.payment_status FROM payment_run_item pi JOIN invoice i ON i.id = pi.invoice_id WHERE pi.payment_run_id = ? ORDER BY i.id',
        );
        $stmt->execute([$runId]);
        $ergebnis = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $status]) {
            $ergebnis[(int) $id] = PaymentStatus::from((string) $status);
        }

        return $ergebnis;
    }

    /**
     * `angewiesen` -> `verworfen` and the receipts freed. False when the
     * run was not `angewiesen` (any more).
     */
    public function verwerfen(int $id, ?int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE payment_run SET status = ?, discarded_by = ?, discarded_at = ? WHERE id = ? AND status = ?');
        $stmt->execute([ZahllaufStatus::Verworfen->value, $userId, $now->format(self::FORMAT), $id, ZahllaufStatus::Angewiesen->value]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('UPDATE payment_run_item SET active_invoice_id = NULL WHERE payment_run_id = ?')->execute([$id]);

        return true;
    }

    /**
     * The SELECT with the counts and the scope: no receipt of the run may
     * lie outside $bereich. COALESCE: a NULL cost center makes the scope
     * condition NULL, which must count as "outside".
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function auswahl(Zugriffsbereich $bereich): array
    {
        [$scope, $parameter] = $bereich->sqlBedingung('si.invoice_date', 'si.cost_center_id');

        return [
            "SELECT r.*,
                    (SELECT COUNT(*) FROM payment_run_item ci WHERE ci.payment_run_id = r.id) AS anzahl_belege,
                    (SELECT COUNT(*) FROM payment_run_item oi JOIN invoice o ON o.id = oi.invoice_id
                      WHERE oi.payment_run_id = r.id AND o.payment_status NOT IN (?, ?)) AS offene_belege,
                    (SELECT COUNT(DISTINCT zi.invoice_id) FROM payment_run_item zi JOIN allocation za ON za.invoice_id = zi.invoice_id
                      WHERE zi.payment_run_id = r.id AND za.created_at >= r.created_at) AS zugeordnete_belege
             FROM payment_run r
             WHERE NOT EXISTS (
                 SELECT 1 FROM payment_run_item sp JOIN invoice si ON si.id = sp.invoice_id
                 WHERE sp.payment_run_id = r.id AND COALESCE({$scope}, 0) = 0
             )",
            [
                PaymentStatus::Bezahlt->value,
                PaymentStatus::Erstattet->value,
                ...$parameter,
            ],
        ];
    }
}
