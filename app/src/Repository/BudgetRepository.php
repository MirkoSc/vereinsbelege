<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BudgetRecord;
use App\Domain\Zugriffsbereich;

/**
 * The `budget` table (issue #92, migrations/044_budget.sql). SQL only -
 * encrypting and the rules live in App\Service\Report\Budgets.
 */
final readonly class BudgetRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The budgets of one year a reader may see, filtered in SQL by the
     * cost-center scope of `report.view`. A budget without a cost center is
     * outside every cost-center scope, like a row without one everywhere
     * else. The period scope of external accounts does not hide a budget
     * row (a year is no date); it narrows the actual figure instead
     * (App\Service\Report\Budgets), so only the cost centers are taken here.
     *
     * @return list<BudgetRecord>
     */
    public function forYear(int $year, Zugriffsbereich $bereich): array
    {
        // Without a period the date column is never used.
        [$bedingung, $parameter] = new Zugriffsbereich($bereich->kostenstellen)->sqlBedingung('b.created_at', 'b.cost_center_id');
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.year, b.category_id, b.cost_center_id, b.dek_sealed, b.data_enc
             FROM budget b WHERE b.year = ? AND ' . $bedingung . ' ORDER BY b.id',
        );
        $stmt->execute([$year, ...$parameter]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id): ?BudgetRecord
    {
        $stmt = $this->pdo->prepare('SELECT id, year, category_id, cost_center_id, dek_sealed, data_enc FROM budget WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** The budget of one year and pair, if there is one. */
    public function idFor(int $year, ?int $categoryId, ?int $costCenterId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM budget WHERE year = ? AND category_key = ? AND cost_center_key = ?');
        $stmt->execute([$year, $categoryId ?? 0, $costCenterId ?? 0]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction.
     */
    public function insert(int $year, ?int $categoryId, ?int $costCenterId, string $dekSealed, ?int $createdBy, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO budget (year, category_id, cost_center_id, dek_sealed, data_enc, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, '', ?, ?, ?)",
        );
        $stmt->bindValue(1, $year, \PDO::PARAM_INT);
        $stmt->bindValue(2, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $costCenterId, $costCenterId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** A new key and ciphertext for an existing row (a changed amount). */
    public function replaceData(int $id, string $dekSealed, string $dataEnc, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE budget SET dek_sealed = ?, data_enc = ?, updated_at = ? WHERE id = ?');
        $stmt->bindValue(1, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->bindValue(4, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function setData(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE budget SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM budget WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): BudgetRecord
    {
        return new BudgetRecord(
            id: (int) $row['id'],
            year: (int) $row['year'],
            categoryId: $row['category_id'] === null ? null : (int) $row['category_id'],
            costCenterId: $row['cost_center_id'] === null ? null : (int) $row['cost_center_id'],
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
        );
    }
}
