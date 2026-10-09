<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AssignmentRuleRecord;
use App\Domain\BankTransactionDirection;

/**
 * The `assignment_rule` table (migrations/027_assignment_rule.sql, M9-6,
 * issue #64, docs/spec/02-datenmodell.md "Fachdaten"). SQL only - the
 * encryption and the rules about rules live in
 * App\Service\Bank\Buchungsregeln.
 */
final readonly class AssignmentRuleRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setCiphertext() in the
     * same transaction.
     */
    public function insert(
        string $dekSealed,
        ?BankTransactionDirection $direction,
        bool $noReceipt,
        ?int $categoryId,
        ?int $createdBy,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO assignment_rule (dek_sealed, data_enc, direction, no_receipt, category_id, active, created_by, created_at, updated_at)
             VALUES (?, '', ?, ?, ?, 1, ?, ?, ?)",
        );
        $stmt->bindValue(1, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $direction?->value);
        $stmt->bindValue(3, $noReceipt ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(4, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setCiphertext(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE assignment_rule SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Changes the structure; the caller writes the new ciphertext in the same transaction. */
    public function update(int $id, ?BankTransactionDirection $direction, bool $noReceipt, ?int $categoryId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE assignment_rule SET direction = ?, no_receipt = ?, category_id = ?, updated_at = ? WHERE id = ?');
        $stmt->bindValue(1, $direction?->value);
        $stmt->bindValue(2, $noReceipt ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(3, $categoryId, $categoryId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format(self::FORMAT));
        $stmt->bindValue(5, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function setActive(int $id, bool $active, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE assignment_rule SET active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $now->format(self::FORMAT), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM assignment_rule WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function find(int $id): ?AssignmentRuleRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assignment_rule WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Every rule, oldest first - the order in which they win (App\Service\
     * Bank\Regelabgleich).
     *
     * @return list<AssignmentRuleRecord>
     */
    public function all(bool $nurAktive = false): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM assignment_rule' . ($nurAktive ? ' WHERE active = 1' : '') . ' ORDER BY id',
        );

        return array_map(self::hydrate(...), $stmt === false ? [] : $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AssignmentRuleRecord
    {
        return new AssignmentRuleRecord(
            id: (int) $row['id'],
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            direction: $row['direction'] === null ? null : BankTransactionDirection::from((string) $row['direction']),
            noReceipt: (int) $row['no_receipt'] === 1,
            categoryId: $row['category_id'] === null ? null : (int) $row['category_id'],
            active: (int) $row['active'] === 1,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
