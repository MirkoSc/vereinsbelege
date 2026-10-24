<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\Umbuchung;
use App\Domain\UmbuchungMethode;
use App\Domain\Zugriffsbereich;

/**
 * Transfers between own accounts (migrations/039_transfer.sql, M10-6,
 * issue #70, docs/spec/04-bank-und-abgleich.md section 5 "Stand M10-6"):
 * the `transfer` table, and the few statements on `bank_transaction` that
 * only a transfer writes (`doc_source = umbuchung`) or needs (the bookings
 * that may still become one). SQL only - the rules live in App\Service\
 * Bank\Umbuchungen. Nothing here reads an amount.
 */
final readonly class TransferRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** MySQL/MariaDB: duplicate entry for a UNIQUE key. */
    private const int DUPLICATE_KEY = 1062;

    /** How many blind indexes one IN (...) lookup carries at most. */
    private const int IN_MAX = 500;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return ?int the new id; null when one of the two bookings is in a
     *         transfer already - the unique keys are the net under the
     *         service's own check
     */
    public function insert(int $ausgabeId, int $einnahmeId, UmbuchungMethode $method, ?int $createdBy, \DateTimeImmutable $now): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transfer (out_transaction_id, in_transaction_id, method, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $ausgabeId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $einnahmeId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $method->value);
        $stmt->bindValue(4, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
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

    /** The transfer a booking is part of, if any. */
    public function forTransaction(int $transactionId): ?Umbuchung
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transfer WHERE out_transaction_id = ? OR in_transaction_id = ?');
        $stmt->execute([$transactionId, $transactionId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM transfer WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Marks a booking as one half of a transfer: no receipt needed, for
     * that reason. Within the caller's transaction, under its row lock.
     */
    public function markieren(int $transactionId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE bank_transaction SET doc_required = 0, doc_status = ?, doc_source = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([BankTransactionDocStatus::NichtNoetig->value, BankTransactionSetBy::Umbuchung->value, $now->format(self::FORMAT), $transactionId]);
    }

    /**
     * Takes the mark back: the receipt status returns to the default of the
     * booking's direction ($belegNoetig, App\Service\Bank\BelegStandard).
     */
    public function aufheben(int $transactionId, bool $belegNoetig, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_transaction SET doc_required = ?, doc_status = ?, doc_source = ?, updated_at = ? WHERE id = ? AND doc_source = ?',
        );
        $stmt->execute([
            $belegNoetig ? 1 : 0,
            BankTransactionDocStatus::fuerNeueBuchung($belegNoetig)->value,
            BankTransactionSetBy::Standard->value,
            $now->format(self::FORMAT),
            $transactionId,
            BankTransactionSetBy::Umbuchung->value,
        ]);
    }

    /**
     * Bookings that may still become a transfer and whose counterparty IBAN
     * is one of $counterpartyBis (blind indexes of the own accounts' IBANs
     * under the purpose of `bank_transaction.counterparty_bi`) - the
     * starting points of the automatic recognition. Not paired yet, no
     * receipt allocated; within $bereich (period scope on booking_date).
     *
     * @param list<string> $counterpartyBis raw 32-byte values
     *
     * @return list<array{string, BankTransactionRecord}> counterparty_bi and row, oldest first
     */
    public function mitEigenerGegenseite(array $counterpartyBis, Zugriffsbereich $bereich): array
    {
        $treffer = [];
        foreach (array_chunk($counterpartyBis, self::IN_MAX) as $teil) {
            [$bedingung, $parameter] = $bereich->sqlBedingung('t.booking_date', null);
            $sql = 'SELECT t.* FROM bank_transaction t WHERE ' . $bedingung . self::offen()
                . sprintf(' AND t.counterparty_bi IN (%s) ORDER BY t.booking_date, t.id', implode(',', array_fill(0, count($teil), '?')));
            $stmt = $this->pdo->prepare($sql);
            $i = 1;
            foreach ($parameter as $wert) {
                $stmt->bindValue($i++, $wert);
            }
            foreach (self::offenParameter() as $wert) {
                $stmt->bindValue($i++, $wert);
            }
            foreach ($teil as $bi) {
                $stmt->bindValue($i++, $bi, \PDO::PARAM_LOB);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $treffer[] = [(string) $row['counterparty_bi'], BankTransactionRepository::hydrateRow($row)];
            }
        }
        usort($treffer, static fn(array $a, array $b): int => [$a[1]->bookingDate, $a[1]->id] <=> [$b[1]->bookingDate, $b[1]->id]);

        return $treffer;
    }

    /**
     * Bookings that may still become a transfer, in one direction, with
     * $von <= booking_date <= $bis: on $kontoId when given, never on
     * $ohneKontoId. Not paired yet, no receipt allocated; within $bereich.
     *
     * @return list<array{?string, BankTransactionRecord}> counterparty_bi and row, by date
     */
    public function offeneGegenbuchungen(
        ?int $kontoId,
        ?int $ohneKontoId,
        BankTransactionDirection $richtung,
        \DateTimeImmutable $von,
        \DateTimeImmutable $bis,
        Zugriffsbereich $bereich,
    ): array {
        [$bedingung, $parameter] = $bereich->sqlBedingung('t.booking_date', null);
        $sql = 'SELECT t.* FROM bank_transaction t WHERE ' . $bedingung . self::offen()
            . ' AND t.direction = ? AND t.booking_date >= ? AND t.booking_date <= ?';
        array_push($parameter, ...self::offenParameter());
        array_push($parameter, $richtung->value, $von->format('Y-m-d'), $bis->format('Y-m-d'));
        if ($kontoId !== null) {
            $sql .= ' AND t.account_id = ?';
            $parameter[] = $kontoId;
        }
        if ($ohneKontoId !== null) {
            $sql .= ' AND t.account_id <> ?';
            $parameter[] = $ohneKontoId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY t.booking_date, t.id');
        $stmt->execute($parameter);

        return array_map(
            static fn(array $row): array => [$row['counterparty_bi'] === null ? null : (string) $row['counterparty_bi'], BankTransactionRepository::hydrateRow($row)],
            $stmt->fetchAll(),
        );
    }

    /** Not paired, no receipt allocated (M10-1) - the condition both lookups share. */
    private static function offen(): string
    {
        return ' AND t.doc_source <> ? AND t.doc_status NOT IN (?, ?)';
    }

    /**
     * @return list<string>
     */
    private static function offenParameter(): array
    {
        return [BankTransactionSetBy::Umbuchung->value, BankTransactionDocStatus::Zugeordnet->value, BankTransactionDocStatus::Teilweise->value];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Umbuchung
    {
        return new Umbuchung(
            id: (int) $row['id'],
            ausgabeId: (int) $row['out_transaction_id'],
            einnahmeId: (int) $row['in_transaction_id'],
            method: UmbuchungMethode::from((string) $row['method']),
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
