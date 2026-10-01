<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BankAccountKind;
use App\Domain\BankAccountRecord;

/**
 * The `bank_account` table (migrations/020_bank_account.sql,
 * docs/spec/02-datenmodell.md "Konten"). SQL only - encrypting and the
 * rules live in App\Service\Bank\BankAccountService.
 *
 * Everything that comes back is ciphertext plus plaintext structure
 * (App\Domain\BankAccountRecord); nothing here can read a name or an IBAN.
 */
final readonly class BankAccountRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every account. The order is the id: sorting by name happens after
     * decrypting.
     *
     * @return list<BankAccountRecord>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM bank_account ORDER BY id');

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id): ?BankAccountRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_account WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * First step of a new row: everything but the ciphertexts, whose AAD
     * needs the id this returns. The caller writes setCiphertexts() in the
     * same transaction.
     */
    public function insert(
        BankAccountKind $kind,
        string $dekSealed,
        ?string $ibanBi,
        \DateTimeImmutable $openingDate,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bank_account (kind, dek_sealed, data_enc, iban_bi, opening_balance_enc, opening_date, active, created_at, updated_at)
             VALUES (?, ?, '', ?, '', ?, 1, ?, ?)",
        );
        $stmt->bindValue(1, $kind->value);
        $stmt->bindValue(2, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $ibanBi, $ibanBi === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(4, $openingDate->format('Y-m-d'));
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setCiphertexts(int $id, string $dataEnc, string $openingBalanceEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE bank_account SET data_enc = ?, opening_balance_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $openingBalanceEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function update(
        int $id,
        string $dataEnc,
        ?string $ibanBi,
        string $openingBalanceEnc,
        \DateTimeImmutable $openingDate,
        bool $active,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE bank_account SET data_enc = ?, iban_bi = ?, opening_balance_enc = ?, opening_date = ?, active = ?, updated_at = ?
             WHERE id = ?',
        );
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $ibanBi, $ibanBi === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(3, $openingBalanceEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $openingDate->format('Y-m-d'));
        $stmt->bindValue(5, $active ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The account that carries this IBAN blind index, other than $ausser -
     * what the uniqueness check and later the statement import (M9-4) ask.
     */
    public function idWithIban(string $ibanBi, ?int $ausser = null): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM bank_account WHERE iban_bi = ? AND id <> ?');
        $stmt->bindValue(1, $ibanBi, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $ausser ?? 0, \PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * How often the account is referred to - an account in use is
     * deactivated, not deleted: its cash counts (`cash_count`). Every later
     * table with an account_id (`bank_import`, `bank_transaction`, M9-4)
     * adds its foreign key with ON DELETE RESTRICT and is counted here.
     */
    public function usageCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cash_count WHERE account_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM bank_account WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): BankAccountRecord
    {
        return new BankAccountRecord(
            id: (int) $row['id'],
            kind: BankAccountKind::from((string) $row['kind']),
            dekSealed: (string) $row['dek_sealed'],
            dataEnc: (string) $row['data_enc'],
            ibanBi: $row['iban_bi'] === null ? null : (string) $row['iban_bi'],
            openingBalanceEnc: (string) $row['opening_balance_enc'],
            openingDate: new \DateTimeImmutable((string) $row['opening_date']),
            active: (bool) $row['active'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
