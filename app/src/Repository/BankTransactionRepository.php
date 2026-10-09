<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\Zugriffsbereich;
use App\Service\Bank\BuchungFilter;

/**
 * The `bank_transaction` table (migrations/025_bank_import.sql, M9-4,
 * issue #62, docs/spec/02-datenmodell.md "Fachdaten"). SQL only - the
 * encryption lives in App\Service\Bank\Import\KontoauszugImport (imported
 * bookings), App\Service\Bank\Buchungen (the list, manual bookings,
 * M9-5) and App\Service\Bank\Buchungsregeln (rules, M9-6).
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
        ?int $ruleId = null,
        BankTransactionSetBy $docSource = BankTransactionSetBy::Standard,
        ?BankTransactionSetBy $categorySource = null,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bank_transaction (account_id, import_id, booking_date, value_date, direction, dek_sealed, data_enc,
                                           dedup_bi, counterparty_bi, doc_required, doc_status, source, created_at, updated_at,
                                           category_id, rule_id, doc_source, category_source)
             VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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
        $stmt->bindValue(15, $ruleId, $ruleId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(16, $docSource->value);
        $stmt->bindValue(17, $categorySource?->value);
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
        if ($filter->regelId !== null) {
            $teile[] = 't.rule_id = ?';
            $parameter[] = $filter->regelId;
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
        BankTransactionSetBy $docSource,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE bank_transaction SET account_id = ?, booking_date = ?, direction = ?, category_id = ?, doc_required = ?,
                                         doc_status = ?, doc_source = ?, category_source = 'manuell', updated_at = ?
             WHERE id = ? AND source = ?",
        );
        $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $bookingDate->format('Y-m-d'));
        $stmt->bindValue(3, $direction->value);
        $stmt->bindValue(4, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $docRequired ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(6, $docStatus->value);
        $stmt->bindValue(7, $docSource->value);
        $stmt->bindValue(8, $now->format(self::FORMAT));
        $stmt->bindValue(9, $id, \PDO::PARAM_INT);
        $stmt->bindValue(10, BankTransactionSource::Manuell->value);
        $stmt->execute();
    }

    /**
     * Sets category and receipt status of an imported booking by hand
     * (M9-6) - what the caller decided, sources included. A manual booking
     * is changed through updateManual().
     */
    public function einordnen(
        int $id,
        ?int $categoryId,
        ?BankTransactionSetBy $categorySource,
        bool $docRequired,
        BankTransactionDocStatus $docStatus,
        BankTransactionSetBy $docSource,
        ?int $ruleId,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_transaction SET category_id = ?, category_source = ?, doc_required = ?, doc_status = ?, doc_source = ?,
                                         rule_id = ?, updated_at = ?
             WHERE id = ? AND source = ?',
        );
        $stmt->bindValue(1, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $categorySource?->value);
        $stmt->bindValue(3, $docRequired ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(4, $docStatus->value);
        $stmt->bindValue(5, $docSource->value);
        $stmt->bindValue(6, $ruleId, $ruleId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->bindValue(8, $id, \PDO::PARAM_INT);
        $stmt->bindValue(9, BankTransactionSource::Import->value);
        $stmt->execute();
    }

    /**
     * The imported bookings a rule may still act on (M9-6): no rule has
     * touched them yet, and the receipt status is still the default or
     * there is no category - within the scope of whoever applies the rule.
     * The period scope applies to `booking_date` as in liste(). With $ids
     * only those rows.
     *
     * @param list<int>|null $ids
     *
     * @return list<BankTransactionRecord>
     */
    public function regelKandidaten(Zugriffsbereich $bereich, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        [$bedingung, $parameter] = $bereich->sqlBedingung('t.booking_date', null);
        $sql = "SELECT t.* FROM bank_transaction t
                WHERE {$bedingung} AND t.source = ? AND t.rule_id IS NULL
                  AND (t.doc_source = ? OR t.category_id IS NULL)";
        $parameter[] = BankTransactionSource::Import->value;
        $parameter[] = BankTransactionSetBy::Standard->value;
        if ($ids !== null) {
            $sql .= sprintf(' AND t.id IN (%s)', implode(',', array_fill(0, count($ids), '?')));
            array_push($parameter, ...$ids);
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY t.booking_date, t.id');
        $stmt->execute($parameter);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Lets a rule act on one booking: the receipt status when $docRequired
     * is given, the category when $categoryId is given. Only while no rule
     * has touched the row - returns whether it was written.
     */
    public function regelSetzen(
        int $id,
        int $ruleId,
        ?bool $docRequired,
        ?BankTransactionDocStatus $docStatus,
        ?int $categoryId,
        \DateTimeImmutable $now,
    ): bool {
        $teile = ['rule_id = ?', 'updated_at = ?'];
        $parameter = [$ruleId, $now->format(self::FORMAT)];
        if ($docRequired !== null && $docStatus !== null) {
            array_push($teile, 'doc_required = ?', 'doc_status = ?', 'doc_source = ?');
            array_push($parameter, $docRequired ? 1 : 0, $docStatus->value, BankTransactionSetBy::Regel->value);
        }
        if ($categoryId !== null) {
            array_push($teile, 'category_id = ?', 'category_source = ?');
            array_push($parameter, $categoryId, BankTransactionSetBy::Regel->value);
        }
        $parameter[] = $id;
        $parameter[] = BankTransactionSource::Import->value;

        $stmt = $this->pdo->prepare(
            'UPDATE bank_transaction SET ' . implode(', ', $teile) . ' WHERE id = ? AND source = ? AND rule_id IS NULL',
        );
        $stmt->execute($parameter);

        return $stmt->rowCount() === 1;
    }

    /**
     * Takes a rule's effect back (M9-6): a receipt status it set returns to
     * the default of the direction - an allocated receipt (M10) stays
     * allocated -, a category it set goes, and the rows forget the rule.
     * What a person set on such a row stays. No vault needed: everything
     * involved is plaintext structure. Returns the number of bookings.
     */
    public function regelZuruecknehmen(int $ruleId, bool $einnahmeBelegNoetig, \DateTimeImmutable $now): int
    {
        $jetzt = $now->format(self::FORMAT);
        $einnahmeStatus = BankTransactionDocStatus::fuerNeueBuchung($einnahmeBelegNoetig)->value;

        $stmt = $this->pdo->prepare(
            "UPDATE bank_transaction
                SET doc_required = CASE direction WHEN 'ausgabe' THEN 1 ELSE ? END,
                    doc_status = CASE WHEN doc_status = ? THEN doc_status WHEN direction = 'ausgabe' THEN ? ELSE ? END,
                    doc_source = ?, updated_at = ?
              WHERE rule_id = ? AND doc_source = ?",
        );
        $stmt->execute([
            $einnahmeBelegNoetig ? 1 : 0,
            BankTransactionDocStatus::Zugeordnet->value,
            BankTransactionDocStatus::Fehlt->value,
            $einnahmeStatus,
            BankTransactionSetBy::Standard->value,
            $jetzt,
            $ruleId,
            BankTransactionSetBy::Regel->value,
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE bank_transaction SET category_id = NULL, category_source = NULL, updated_at = ? WHERE rule_id = ? AND category_source = ?',
        );
        $stmt->execute([$jetzt, $ruleId, BankTransactionSetBy::Regel->value]);

        $stmt = $this->pdo->prepare('UPDATE bank_transaction SET rule_id = NULL WHERE rule_id = ?');
        $stmt->execute([$ruleId]);

        return $stmt->rowCount();
    }

    /**
     * The ids of the bookings a rule has acted on.
     *
     * @return list<int>
     */
    public function idsForRule(int $ruleId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM bank_transaction WHERE rule_id = ? ORDER BY id');
        $stmt->execute([$ruleId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many bookings each rule has acted on, within the reader's scope.
     *
     * @return array<int, int> rule id => count, rules without bookings left out
     */
    public function countByRule(Zugriffsbereich $bereich): array
    {
        [$bedingung, $parameter] = $bereich->sqlBedingung('t.booking_date', null);
        $stmt = $this->pdo->prepare(
            "SELECT t.rule_id, COUNT(*) FROM bank_transaction t WHERE {$bedingung} AND t.rule_id IS NOT NULL GROUP BY t.rule_id",
        );
        $stmt->execute($parameter);
        $anzahl = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$regel, $zahl]) {
            $anzahl[(int) $regel] = (int) $zahl;
        }

        return $anzahl;
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
            ruleId: $row['rule_id'] === null ? null : (int) $row['rule_id'],
            docSource: BankTransactionSetBy::from((string) $row['doc_source']),
            categorySource: $row['category_source'] === null ? null : BankTransactionSetBy::from((string) $row['category_source']),
        );
    }
}
