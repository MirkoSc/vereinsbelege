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
     * Every supplier not merged into another, optionally of one role. The
     * order is the id: sorting by name happens after decrypting.
     *
     * @return list<SupplierRecord>
     */
    public function all(?SupplierRole $role = null): array
    {
        if ($role === null) {
            $stmt = $this->pdo->query('SELECT * FROM supplier WHERE merged_into IS NULL ORDER BY id');
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM supplier WHERE merged_into IS NULL AND role = ? ORDER BY id');
            $stmt->execute([$role->value]);
        }

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id): ?SupplierRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction.
     */
    public function insert(
        SupplierRole $role,
        ?int $defaultCategoryId,
        SupplierOrigin $createdVia,
        string $dekSealed,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO supplier (role, dek_sealed, data_enc, default_category_id, created_via, needs_review, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, 0, ?, ?)",
        );
        $stmt->bindValue(1, $role->value);
        $stmt->bindValue(2, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $defaultCategoryId, $defaultCategoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $createdVia->value);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->bindValue(6, $now->format(self::FORMAT));
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

    public function update(int $id, SupplierRole $role, ?int $defaultCategoryId, string $dataEnc, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier SET role = ?, default_category_id = ?, data_enc = ?, updated_at = ? WHERE id = ?',
        );
        $stmt->bindValue(1, $role->value);
        $stmt->bindValue(2, $defaultCategoryId, $defaultCategoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->bindValue(5, $id, \PDO::PARAM_INT);
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
     * check and later the automatic resolution (M7-6) ask.
     *
     * @return list<int>
     */
    public function idsWithKey(SupplierKeyKind $kind, string $bi, ?int $ausser = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT k.supplier_id FROM supplier_key k JOIN supplier s ON s.id = k.supplier_id
             WHERE k.kind = ? AND k.value_bi = ? AND s.merged_into IS NULL AND s.id <> ? ORDER BY k.supplier_id',
        );
        $stmt->bindValue(1, $kind->value);
        $stmt->bindValue(2, $bi, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $ausser ?? 0, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
