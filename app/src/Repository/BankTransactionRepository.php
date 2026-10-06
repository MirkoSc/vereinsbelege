<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSource;
use App\Domain\Zugriffsbereich;
use App\Service\Bank\BuchungFilter;

/**
 * The `bank_transaction` table (migrations/025_bank_import.sql, M9-4,
 * issue #62, docs/spec/02-datenmodell.md "Fachdaten"). SQL only - the
 * encryption lives in App\Service\Bank\Import\KontoauszugImport (imported
 * bookings) and App\Service\Bank\Buchungen (the list, manual bookings,
 * M9-5).
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
        ?int $categoryId = null,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bank_transaction (account_id, import_id, booking_date, value_date, direction, dek_sealed, data_enc,
                                           dedup_bi, counterparty_bi, doc_required, doc_status, source, created_at, updated_at,
                                           category_id)
             VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?)",
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
        $stmt->bindValue(14, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
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
     * balance of a day is summed from (App\Service\Bank\Import\
     * KontoauszugImport, App\Service\Bank\Buchungen::summe()).
     *
     * @return list<BankTransactionRecord>
     */
    public function between(int $accountId, \DateTimeImmutable $von, \DateTimeImmutable $bis): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bank_transaction WHERE account_id = ? AND booking_date >= ? AND booking_date < ? ORDER BY booking_date, id',
        );
        $stmt->execute([$accountId, $von->format('Y-m-d'), $bis->format('Y-m-d')]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * The booking list (M9-5): every structural filter and the reader's
     * scope in SQL, newest first. The period scope of external roles
     * (docs/spec/01-sicherheit.md section 4) applies to `booking_date`; a
     * booking has no cost center, so a cost-center scope sees none. The
     * search text is the caller's - it needs the plaintext.
     *
     * @return list<BankTransactionRecord>
     */
    public function liste(BuchungFilter $filter, Zugriffsbereich $bereich): array
    {
        [$bedingung, $parameter] = $bereich->sqlBedingung('t.booking_date', null);
        $teile = [$bedingung];
        if ($filter->kontoId !== null) {
            $teile[] = 't.account_id = ?';
            $parameter[] = $filter->kontoId;
        }
        if ($filter->von !== null) {
            $teile[] = 't.booking_date >= ?';
            $parameter[] = $filter->von->format('Y-m-d');
        }
        if ($filter->bis !== null) {
            $teile[] = 't.booking_date <= ?';
            $parameter[] = $filter->bis->format('Y-m-d');
        }
        if ($filter->richtung !== null) {
            $teile[] = 't.direction = ?';
            $parameter[] = $filter->richtung->value;
        }
        if ($filter->belegStatus !== null) {
            $teile[] = 't.doc_status = ?';
            $parameter[] = $filter->belegStatus->value;
        }
        if ($filter->quelle !== null) {
            $teile[] = 't.source = ?';
            $parameter[] = $filter->quelle->value;
        }
        if ($filter->kategorieId === 0) {
            $teile[] = 't.category_id IS NULL';
        } elseif ($filter->kategorieId !== null) {
            $teile[] = 't.category_id = ?';
            $parameter[] = $filter->kategorieId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM bank_transaction t WHERE ' . implode(' AND ', $teile) . ' ORDER BY t.booking_date DESC, t.id DESC',
        );
        $stmt->execute($parameter);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id): ?BankTransactionRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_transaction WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Changes the structure of a manual booking; the caller writes the new
     * ciphertext with setCiphertext() in the same transaction. An imported
     * booking is never changed here.
     */
    public function updateManual(
        int $id,
        int $accountId,
        \DateTimeImmutable $bookingDate,
        BankTransactionDirection $direction,
        ?int $categoryId,
        bool $docRequired,
        BankTransactionDocStatus $docStatus,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_transaction SET account_id = ?, booking_date = ?, direction = ?, category_id = ?, doc_required = ?,
                                         doc_status = ?, updated_at = ?
             WHERE id = ? AND source = ?',
        );
        $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $bookingDate->format('Y-m-d'));
        $stmt->bindValue(3, $direction->value);
        $stmt->bindValue(4, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $docRequired ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(6, $docStatus->value);
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->bindValue(8, $id, \PDO::PARAM_INT);
        $stmt->bindValue(9, BankTransactionSource::Manuell->value);
        $stmt->execute();
    }

    /** Deletes a manual booking; an imported one stays. Returns whether a row went. */
    public function deleteManual(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM bank_transaction WHERE id = ? AND source = ?');
        $stmt->execute([$id, BankTransactionSource::Manuell->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): BankTransactionRecord
    {
        return new BankTransactionRecord(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            importId: $row['import_id'] === null ? null : (int) $row['import_id'],
            bookingDate: new \DateTimeImmutable((string) $row['booking_date']),
            direction: BankTransactionDirection::from((string) $row['direction']),
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            valueDate: $row['value_date'] === null ? null : new \DateTimeImmutable((string) $row['value_date']),
            categoryId: $row['category_id'] === null ? null : (int) $row['category_id'],
            docRequired: (int) $row['doc_required'] === 1,
            docStatus: BankTransactionDocStatus::from((string) $row['doc_status']),
            source: BankTransactionSource::from((string) $row['source']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
