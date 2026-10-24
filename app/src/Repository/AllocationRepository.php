<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AllocationRecord;
use App\Domain\ZuordnungMethode;

/**
 * The `allocation` table (migrations/037_allocation.sql, M10-1, issue #65,
 * docs/spec/02-datenmodell.md "Fachdaten"). SQL only - encrypting the
 * amount, the rules and the derived statuses live in App\Service\Bank\
 * Zuordnung. Everything that comes back is ciphertext plus plaintext
 * structure (App\Domain\AllocationRecord); nothing here can read an amount.
 */
final readonly class AllocationRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    private const int DUPLICATE_KEY = 1062;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setAmount() in the same
     * transaction.
     *
     * @param array<string, mixed>|null $ruleTrace
     *
     * @return ?int null when the pair already has a row - another request
     *         linked the two first; the unique key is the net under the
     *         service's own check
     */
    public function insert(
        int $invoiceId,
        int $transactionId,
        string $dekSealed,
        ZuordnungMethode $method,
        ?int $score,
        ?array $ruleTrace,
        ?int $createdBy,
        \DateTimeImmutable $now,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO allocation (invoice_id, transaction_id, dek_sealed, amount_enc, method, score, rule_trace, created_by, created_at)
             VALUES (?, ?, ?, '', ?, ?, ?, ?, ?)",
        );
        $stmt->bindValue(1, $invoiceId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $transactionId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $method->value);
        $stmt->bindValue(5, $score, $score === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $ruleTrace === null ? null : json_encode($ruleTrace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $stmt->bindValue(7, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(8, $now->format(self::FORMAT));
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_KEY) {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function setAmount(int $id, string $amountEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE allocation SET amount_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $amountEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $id): ?AllocationRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allocation WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : AllocationRecord::fromRow($row);
    }

    /**
     * @param bool $sperren locking read: sees what other transactions
     *        committed since this one's snapshot (REPEATABLE READ) and locks
     *        the rows - for every sum checked or derived inside a transaction
     *
     * @return list<AllocationRecord>
     */
    public function forInvoice(int $invoiceId, bool $sperren = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allocation WHERE invoice_id = ? ORDER BY id' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([$invoiceId]);

        return array_map(AllocationRecord::fromRow(...), $stmt->fetchAll());
    }

    /**
     * @param bool $sperren locking read: sees what other transactions
     *        committed since this one's snapshot (REPEATABLE READ) and locks
     *        the rows - for every sum checked or derived inside a transaction
     *
     * @return list<AllocationRecord>
     */
    public function forTransaction(int $transactionId, bool $sperren = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allocation WHERE transaction_id = ? ORDER BY id' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([$transactionId]);

        return array_map(AllocationRecord::fromRow(...), $stmt->fetchAll());
    }

    /**
     * The allocations of many receipts at once (the ZIP export, issue #76),
     * grouped by invoice id - in chunks, so the IN list stays short.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, list<AllocationRecord>>
     */
    public function forInvoices(array $invoiceIds): array
    {
        $ergebnis = [];
        foreach (array_chunk(array_values(array_unique($invoiceIds)), 500) as $teil) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT * FROM allocation WHERE invoice_id IN (%s) ORDER BY invoice_id, id',
                implode(',', array_fill(0, count($teil), '?')),
            ));
            $stmt->execute($teil);
            foreach ($stmt->fetchAll() as $row) {
                $record = AllocationRecord::fromRow($row);
                $ergebnis[$record->invoiceId][] = $record;
            }
        }

        return $ergebnis;
    }

    /** @param bool $sperren locking read, as forTransaction() */
    public function countForInvoice(int $invoiceId, bool $sperren = false): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM allocation WHERE invoice_id = ?' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([$invoiceId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM allocation WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }
}
