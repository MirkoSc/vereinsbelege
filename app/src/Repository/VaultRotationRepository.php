<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The SQL of the vault key rotation (issue #90, docs/spec/01-sicherheit.md
 * section 2 "Schlüsselrotation"): which tables carry a sealed data key, which
 * rows are still sealed to the old generation, and the per-row write.
 *
 * The tables are found in the schema, not listed here: every table with a
 * `dek_sealed` column takes part, including ones a later migration adds - a
 * forgotten table would otherwise keep data keys only the retired generation
 * opens, and the rotation would lose it the moment the old grants go.
 *
 * `audit_log` is the one exception in how it is written: its `dek_sealed` is
 * covered by the hash chain (migration 040), so the re-sealed key goes into
 * `dek_resealed`, and "the sealed key of this row" is
 * COALESCE(dek_resealed, dek_sealed).
 *
 * The generation of a sealed key is its first byte (App\Service\Crypto\
 * Vault::sealedVersion()); MariaDB reads it with ORD(LEFT(col, 1)).
 */
final readonly class VaultRotationRepository
{
    public const string AUDIT_TABLE = 'audit_log';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every table with a `dek_sealed` column and its single-column primary
     * key, by table name.
     *
     * @return array<string, string> table => primary key column
     *
     * @throws \LogicException for a table without a usable primary key - the
     *         rotation must not silently skip it
     */
    public function tabellen(): array
    {
        $stmt = $this->pdo->query(
            "SELECT c.TABLE_NAME AS t, k.COLUMN_NAME AS pk
             FROM information_schema.COLUMNS c
             LEFT JOIN information_schema.KEY_COLUMN_USAGE k
               ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.CONSTRAINT_NAME = 'PRIMARY'
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'dek_sealed'
             ORDER BY c.TABLE_NAME, k.ORDINAL_POSITION",
        );
        $tabellen = [];
        $anzahl = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tabelle = (string) $row['t'];
            $pk = $row['pk'] === null ? '' : (string) $row['pk'];
            if (!self::name($tabelle) || !self::name($pk)) {
                throw new \LogicException(sprintf('Table "%s" has no usable primary key for the key rotation.', $tabelle));
            }
            $tabellen[$tabelle] = $pk;
            $anzahl[$tabelle] = ($anzahl[$tabelle] ?? 0) + 1;
        }
        foreach ($anzahl as $tabelle => $n) {
            if ($n !== 1) {
                throw new \LogicException(sprintf('Table "%s" has a composite primary key; the key rotation needs a single column.', $tabelle));
            }
        }

        return $tabellen;
    }

    /**
     * How many rows of the table are still sealed to $version.
     */
    public function anzahl(string $tabelle, int $version): int
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE ORD(LEFT(%s, 1)) = ?',
            self::pruefe($tabelle),
            self::spalte($tabelle),
        ));
        $stmt->execute([$version]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The next ids still sealed to $version, after $nach, ascending.
     *
     * @return list<int>
     */
    public function naechste(string $tabelle, string $pk, int $version, int $nach, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT `%2$s` FROM `%1$s` WHERE ORD(LEFT(%3$s, 1)) = ? AND `%2$s` > ? ORDER BY `%2$s` LIMIT %4$d',
            self::pruefe($tabelle),
            self::pruefe($pk),
            self::spalte($tabelle),
            max(1, $limit),
        ));
        $stmt->bindValue(1, $version, \PDO::PARAM_INT);
        $stmt->bindValue(2, $nach, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The whole row, locked until the caller's transaction ends - so nothing
     * changes it between reading the old key and writing the new one. The
     * row's current sealed key is under '__dek'.
     *
     * @return array<string, mixed>|null
     */
    public function sperre(string $tabelle, string $pk, int $id): ?array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT *, %3$s AS __dek FROM `%1$s` WHERE `%2$s` = ? FOR UPDATE',
            self::pruefe($tabelle),
            self::pruefe($pk),
            self::spalte($tabelle),
        ));
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Writes the re-sealed key: `dek_sealed`, or for the audit log
     * `dek_resealed` (outside the hash chain).
     */
    public function schreibe(string $tabelle, string $pk, int $id, string $dekSealed): void
    {
        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE `%s` SET %s = ? WHERE `%s` = ?',
            self::pruefe($tabelle),
            $tabelle === self::AUDIT_TABLE ? 'dek_resealed' : 'dek_sealed',
            self::pruefe($pk),
        ));
        $stmt->bindValue(1, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Sets one column of the locked row - what a blind index handler writes
     * (issue #90: every stored index is recomputed under the new key).
     */
    public function setzeSpalte(string $tabelle, string $pk, int $id, string $spalte, ?string $wert): void
    {
        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE `%s` SET `%s` = ? WHERE `%s` = ?',
            self::pruefe($tabelle),
            self::pruefe($spalte),
            self::pruefe($pk),
        ));
        $stmt->bindValue(1, $wert, $wert === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Clears a blind index column in every row - for a table without a
     * sealed key of its own, which the row-by-row chain never visits.
     */
    public function leereSpalte(string $tabelle, string $spalte): void
    {
        $this->pdo->exec(sprintf('UPDATE `%s` SET `%s` = NULL', self::pruefe($tabelle), self::pruefe($spalte)));
    }

    /**
     * Whether a value of a unique-per-account blind index is taken - for the
     * duplicate key of a booking, whose running number has to stay free.
     *
     * @param list<string> $werte
     *
     * @return array<string, true>
     */
    public function vorhandeneDedup(int $accountId, array $werte): array
    {
        if ($werte === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT dedup_bi FROM bank_transaction WHERE account_id = ? AND dedup_bi IN (%s)',
            implode(', ', array_fill(0, count($werte), '?')),
        ));
        $stmt->bindValue(1, $accountId, \PDO::PARAM_INT);
        foreach (array_values($werte) as $i => $wert) {
            $stmt->bindValue($i + 2, $wert, \PDO::PARAM_LOB);
        }
        $stmt->execute();
        $vorhanden = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $wert) {
            $vorhanden[(string) $wert] = true;
        }

        return $vorhanden;
    }

    private static function spalte(string $tabelle): string
    {
        return $tabelle === self::AUDIT_TABLE ? 'COALESCE(dek_resealed, dek_sealed)' : 'dek_sealed';
    }

    private static function pruefe(string $name): string
    {
        if (!self::name($name)) {
            throw new \LogicException(sprintf('Not a plain identifier: "%s".', $name));
        }

        return $name;
    }

    private static function name(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1;
    }
}
