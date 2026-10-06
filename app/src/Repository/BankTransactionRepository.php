<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSource;

/**
 * The `bank_transaction` table (migrations/024_bank_import.sql, M9-4,
 * issue #62, docs/spec/02-datenmodell.md "Fachdaten"). SQL only - the
 * encryption lives in App\Service\Bank\KontoauszugImport.
 */
final readonly class BankTransactionRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** How many blind indexes one IN (...) lookup carries at most. */
    private const int IN_MAX = 500;

    /** MySQL/MariaDB: duplicate entry for a UNIQUE key. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Which of these dedup blind indexes the account already has - one
     * point lookup per chunk through `uq_bank_transaction_dedup`.
     *
     * @param list<string> $dedupBis raw 32-byte values
     *
     * @return array<string, true> the ones that exist, keyed by their raw bytes
     */
    public function existingDedup(int $accountId, array $dedupBis): array
    {
        $vorhanden = [];
        foreach (array_chunk(array_values(array_unique($dedupBis)), self::IN_MAX) as $teil) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT dedup_bi FROM bank_transaction WHERE account_id = ? AND dedup_bi IN (%s)',
                implode(',', array_fill(0, count($teil), '?')),
            ));
            $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
            foreach ($teil as $i => $bi) {
                $stmt->bindValue($i + 2, $bi, \PDO::PARAM_LOB);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $bi) {
                $vorhanden[(string) $bi] = true;
            }
        }

        return $vorhanden;
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setCiphertext() in the
     * same transaction.
     *
     * @return ?int null when the account already has this dedup blind index
     *         - another request wrote the same booking first; the unique key
     *         is the net under the caller's own existingDedup() check
     */
    public function insert(
        int $accountId,
        ?int $importId,
        \DateTimeImmutable $bookingDate,
        ?\DateTimeImmutable $valueDate,
        BankTransactionDirection $direction,
        string $dekSealed,
        ?string $dedupBi,
        ?string $counterpartyBi,
        BankTransactionDocStatus $docStatus,
        bool $docRequired,
        BankTransactionSource $source,
        \DateTimeImmutable $now,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bank_transaction (account_id, import_id, booking_date, value_date, direction, dek_sealed, data_enc,
                                           dedup_bi, counterparty_bi, doc_required, doc_status, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?)",
        );
        $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $importId, $importId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $bookingDate->format('Y-m-d'));
        $stmt->bindValue(4, $valueDate?->format('Y-m-d'));
        $stmt->bindValue(5, $direction->value);
        $stmt->bindValue(6, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(7, $dedupBi, $dedupBi === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(8, $counterpartyBi, $counterpartyBi === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(9, $docRequired ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(10, $docStatus->value);
        $stmt->bindValue(11, $source->value);
        $stmt->bindValue(12, $now->format(self::FORMAT));
        $stmt->bindValue(13, $now->format(self::FORMAT));
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

    public function setCiphertext(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE bank_transaction SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countForImport(int $importId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM bank_transaction WHERE import_id = ?');
        $stmt->execute([$importId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The account's bookings with $von <= booking_date < $bis - what the
     * balance of a day is summed from (App\Service\Bank\KontoauszugImport).
     *
     * @return list<BankTransactionRecord>
     */
    public function between(int $accountId, \DateTimeImmutable $von, \DateTimeImmutable $bis): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, import_id, booking_date, direction, dek_sealed, data_enc FROM bank_transaction
             WHERE account_id = ? AND booking_date >= ? AND booking_date < ? ORDER BY booking_date, id',
        );
        $stmt->execute([$accountId, $von->format('Y-m-d'), $bis->format('Y-m-d')]);

        return array_map(
            static fn(array $row): BankTransactionRecord => new BankTransactionRecord(
                id: (int) $row['id'],
                accountId: (int) $row['account_id'],
                importId: $row['import_id'] === null ? null : (int) $row['import_id'],
                bookingDate: new \DateTimeImmutable((string) $row['booking_date']),
                direction: BankTransactionDirection::from((string) $row['direction']),
                dekSealed: (string) $row['dek_sealed'],
                dataEnc: (string) $row['data_enc'],
            ),
            $stmt->fetchAll(),
        );
    }
}
