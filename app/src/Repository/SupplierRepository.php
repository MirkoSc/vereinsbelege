<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\SupplierKeyKind;
use App\Domain\SupplierOrigin;
use App\Domain\SupplierRecord;
use App\Domain\SupplierRole;

/**
 * The `supplier` and `supplier_key` tables (migrations/018_supplier.sql,
 * docs/spec/02-datenmodell.md "Lieferanten"). SQL only - encrypting,
 * deciding which keys a supplier has and the rules live in
 * App\Service\MasterData\SupplierService.
 *
 * Everything that comes back is ciphertext plus plaintext structure
 * (App\Domain\SupplierRecord); nothing here can read a name.
 */
final readonly class SupplierRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every supplier not merged into another, optionally of one role and
     * optionally only those still to review (`needs_review`, the list "Neue
     * Lieferanten prüfen", issue #48/M7-6). The order is the id: sorting by
     * name happens after decrypting.
     *
     * @return list<SupplierRecord>
     */
    public function all(?SupplierRole $role = null, bool $nurZuPruefen = false): array
    {
        $sql = 'SELECT * FROM supplier WHERE merged_into IS NULL';
        $params = [];
        if ($role !== null) {
            $sql .= ' AND role = ?';
            $params[] = $role->value;
        }
        if ($nurZuPruefen) {
            $sql .= ' AND needs_review = 1';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * How many suppliers the AI created that nobody has looked at yet
     * (issue #48/M7-6) - plaintext, the list page shows it without vault.
     */
    public function anzahlZuPruefen(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM supplier WHERE merged_into IS NULL AND needs_review = 1')->fetchColumn();
    }

    /**
     * "Als geprüft markieren" (issue #48/M7-6) - whether the row still was
     * to review: a second click changes nothing and writes no audit line.
     */
    public function markiereGeprueft(int $id, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE supplier SET needs_review = 0, updated_at = ? WHERE id = ? AND needs_review = 1 AND merged_into IS NULL');
        $stmt->execute([$now->format(self::FORMAT), $id]);

        return $stmt->rowCount() === 1;
    }

    public function find(int $id): ?SupplierRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The row under a row lock until the transaction ends - merging
     * (issue #39/M6-5) reads both suppliers this way, so two merges or a
     * merge and a save cannot interleave.
     */
    public function sperre(int $id): ?SupplierRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The supplier a receipt should show: a merged one stands for the one
     * it was merged into. One step is enough - markiereZusammengefuehrt()
     * keeps the chains flat.
     */
    public function aufgeloest(int $id): int
    {
        return $this->find($id)?->mergedInto ?? $id;
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction. $needsReview for a supplier the AI created (issue
     * #48/M7-6).
     */
    public function insert(
        SupplierRole $role,
        ?int $defaultCategoryId,
        SupplierOrigin $createdVia,
        string $dekSealed,
        \DateTimeImmutable $now,
        bool $needsReview = false,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO supplier (role, dek_sealed, data_enc, default_category_id, created_via, needs_review, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, ?, ?, ?)",
        );
        $stmt->bindValue(1, $role->value);
        $stmt->bindValue(2, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $defaultCategoryId, $defaultCategoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $createdVia->value);
        $stmt->bindValue(5, $needsReview ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setData(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE supplier SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * $geprueft settles `needs_review` as well (issue #48/M7-6): saving the
     * form means a person has looked at the supplier. Merging (M6-5) keeps
     * the target's flag and passes false.
     */
    public function update(int $id, SupplierRole $role, ?int $defaultCategoryId, string $dataEnc, \DateTimeImmutable $now, bool $geprueft = false): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier SET role = ?, default_category_id = ?, data_enc = ?, updated_at = ?,
                    needs_review = CASE WHEN ? = 1 THEN 0 ELSE needs_review END
             WHERE id = ?',
        );
        $stmt->bindValue(1, $role->value);
        $stmt->bindValue(2, $defaultCategoryId, $defaultCategoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->bindValue(5, $geprueft ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(6, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Replaces every key of the supplier with the given ones.
     *
     * @param list<array{SupplierKeyKind, string}> $keys kind and blind index (32 raw bytes)
     */
    public function replaceKeys(int $supplierId, array $keys): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier_key WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        $insert = $this->pdo->prepare('INSERT INTO supplier_key (supplier_id, kind, value_bi) VALUES (?, ?, ?)');
        foreach ($keys as [$kind, $bi]) {
            $insert->bindValue(1, $supplierId, \PDO::PARAM_INT);
            $insert->bindValue(2, $kind->value);
            $insert->bindValue(3, $bi, \PDO::PARAM_LOB);
            $insert->execute();
        }
    }

    /**
     * @return list<array{SupplierKeyKind, string}> kind and blind index, in insertion order
     */
    public function keys(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT kind, value_bi FROM supplier_key WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$supplierId]);

        return array_map(
            static fn(array $row): array => [SupplierKeyKind::from((string) $row['kind']), (string) $row['value_bi']],
            $stmt->fetchAll(),
        );
    }

    /**
     * Suppliers (not merged away) that carry this key - what the uniqueness
     * check and the automatic resolution (issue #48/M7-6) ask.
     *
     * $sperren reads with FOR UPDATE, inside the caller's transaction: the
     * resolution creates a supplier when none carries the key, and the
     * locks on the key's index range keep a second request from creating
     * the same supplier at the same moment (InnoDB answers the loser with a
     * deadlock, App\Service\Document\LieferantenAufloesung tries again).
     *
     * @return list<int>
     */
    public function idsWithKey(SupplierKeyKind $kind, string $bi, ?int $ausser = null, bool $sperren = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT k.supplier_id FROM supplier_key k JOIN supplier s ON s.id = k.supplier_id
             WHERE k.kind = ? AND k.value_bi = ? AND s.merged_into IS NULL AND s.id <> ? ORDER BY k.supplier_id'
            . ($sperren ? ' FOR UPDATE' : ''),
        );
        $stmt->bindValue(1, $kind->value);
        $stmt->bindValue(2, $bi, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $ausser ?? 0, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Which suppliers (not merged away) carry any of these blind indexes -
     * the preselection of the prompt (issue #48/M7-6) asks for the
     * thousands of values of a receipt's text at once, in a few queries.
     * The purpose is part of each blind index, so a value only matches the
     * kind it was hashed for.
     *
     * @param list<string> $bis blind indexes (32 raw bytes)
     *
     * @return array<string, list<int>> blind index => supplier ids
     */
    public function idsMitSchluesseln(array $bis): array
    {
        $treffer = [];
        foreach (array_chunk(array_values(array_unique($bis)), 500) as $teil) {
            $stmt = $this->pdo->prepare(
                'SELECT k.value_bi, k.supplier_id FROM supplier_key k JOIN supplier s ON s.id = k.supplier_id
                 WHERE s.merged_into IS NULL AND k.value_bi IN (' . implode(', ', array_fill(0, count($teil), '?')) . ')
                 ORDER BY k.supplier_id',
            );
            foreach ($teil as $i => $bi) {
                $stmt->bindValue($i + 1, $bi, \PDO::PARAM_LOB);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll() as $zeile) {
                $treffer[(string) $zeile['value_bi']][] = (int) $zeile['supplier_id'];
            }
        }

        return $treffer;
    }

    /**
     * How often the supplier is referred to - a supplier in use is not
     * deleted: a supplier merged into this one and the receipts of the
     * review page (`invoice`, issue #37/M6-3). Every later table with a
     * supplier_id (recurring_series, assignment_rule) adds its foreign key
     * with ON DELETE RESTRICT and is counted here.
     */
    public function usageCount(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM supplier WHERE merged_into = ?)
                  + (SELECT COUNT(*) FROM invoice WHERE supplier_id = ?)',
        );
        $stmt->execute([$id, $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Second half of merging (issue #39/M6-5): the source points at the
     * target, and so does every supplier merged into the source before -
     * `merged_into` is never more than one step. The source's keys go: the
     * target now carries them, and a merged supplier must not be found by
     * the uniqueness check or the resolution (M7-6). The row itself stays;
     * locked receipts still refer to it.
     */
    public function markiereZusammengefuehrt(int $quelle, int $ziel, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE supplier SET merged_into = ?, updated_at = ? WHERE merged_into = ? OR id = ?');
        $stmt->bindValue(1, $ziel, \PDO::PARAM_INT);
        $stmt->bindValue(2, $now->format(self::FORMAT));
        $stmt->bindValue(3, $quelle, \PDO::PARAM_INT);
        $stmt->bindValue(4, $quelle, \PDO::PARAM_INT);
        $stmt->execute();

        $this->replaceKeys($quelle, []);
    }

    /** The keys go with the row (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): SupplierRecord
    {
        return new SupplierRecord(
            id: (int) $row['id'],
            role: SupplierRole::from((string) $row['role']),
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            defaultCategoryId: $row['default_category_id'] === null ? null : (int) $row['default_category_id'],
            createdVia: SupplierOrigin::from((string) $row['created_via']),
            needsReview: (bool) $row['needs_review'],
            mergedInto: $row['merged_into'] === null ? null : (int) $row['merged_into'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
