<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\CashCountRecord;
use App\Domain\Zugriffsbereich;

/**
 * The `cash_count` table (migrations/020_bank_account.sql,
 * docs/spec/02-datenmodell.md "Konten"). SQL only - encrypting and the
 * rules live in App\Service\Bank\Kassensturz. Append-only: there is no
 * update and no delete.
 */
final readonly class CashCountRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The counts of one cash box within the reader's scope, newest first.
     * The period scope of external roles (docs/spec/01-sicherheit.md
     * section 4) filters on `counted_on` here, in SQL.
     *
     * @return list<CashCountRecord>
     */
    public function forAccount(int $accountId, Zugriffsbereich $bereich): array
    {
        [$bedingung, $parameter] = $bereich->sqlBedingung('c.counted_on', null);
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM cash_count c WHERE c.account_id = ? AND ' . $bedingung . '
             ORDER BY c.counted_on DESC, c.id DESC',
        );
        $stmt->execute([$accountId, ...$parameter]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction.
     */
    public function insert(int $accountId, \DateTimeImmutable $countedOn, string $dekSealed, ?int $createdBy, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO cash_count (account_id, counted_on, dek_sealed, data_enc, created_by, created_at)
             VALUES (?, ?, ?, '', ?, ?)",
        );
        $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $countedOn->format('Y-m-d'));
        $stmt->bindValue(3, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $createdBy, $createdBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setData(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE cash_count SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CashCountRecord
    {
        return new CashCountRecord(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            countedOn: new \DateTimeImmutable((string) $row['counted_on']),
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
