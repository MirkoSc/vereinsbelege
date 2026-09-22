<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Pure-PHP MySQL/MariaDB dump (deliberately self-implemented instead of
 * depending on a library, CLAUDE.md section 1): SHOW TABLES, SHOW CREATE
 * TABLE, batched INSERTs. The output restores cleanly through SqlSplitter +
 * PDO::exec.
 *
 * Text values are quoted through PDO::quote. Binary columns are not
 * (M2-6): the dump carries `SET NAMES utf8mb4`, and the ciphertext of a blob
 * is not valid UTF-8 - `dek_sealed`, `header`, `cipher_sha256` and above all
 * `file_blob_chunk.data` would go through a charset conversion that is not
 * ours to trust. They are written as hexadecimal literals instead, which mean
 * the same bytes in any charset.
 *
 * Storage backend `db` puts the whole receipt archive into `file_blob_chunk`,
 * so the dump has to hold its own with a table far larger than memory: rows
 * are read unbuffered and an INSERT is flushed by byte budget, not only by
 * row count.
 */
final readonly class MysqlDumper
{
    private const int ROWS_PER_INSERT = 100;

    /**
     * Flush an INSERT once the collected rows reach this. A chunk row is
     * 256 KiB of ciphertext and doubles as hex, so a hundred of them in one
     * statement would run into max_allowed_packet on the way back in.
     */
    private const int BYTES_PER_INSERT = 512 * 1024;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param resource $stream
     */
    public function dump($stream): void
    {
        fwrite($stream, "-- Vereinsbelege DB dump\n");
        fwrite($stream, "SET NAMES utf8mb4;\n");
        fwrite($stream, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->dumpTable($stream, (string) $table);
        }

        fwrite($stream, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    /**
     * @param resource $stream
     */
    private function dumpTable($stream, string $table): void
    {
        fwrite($stream, sprintf("DROP TABLE IF EXISTS `%s`;\n", $table));

        $create = $this->pdo
            ->query(sprintf('SHOW CREATE TABLE `%s`', $table))
            ->fetch();
        fwrite($stream, (string) ($create['Create Table'] ?? '') . ";\n");

        // Everything the connection needs to know about the table is asked
        // here: nothing else may travel over it while the unbuffered SELECT
        // below is being read. PDO::quote() is client-side and therefore fine.
        $binary = $this->binaryColumns($table);

        $columns = null;
        $batch = [];
        $bytes = 0;
        $this->rowsUnbuffered(true);
        try {
            $select = $this->pdo->query(sprintf('SELECT * FROM `%s`', $table));
            while (($row = $select->fetch()) !== false) {
                $columns ??= '`' . implode('`, `', array_keys($row)) . '`';

                $values = [];
                foreach ($row as $column => $value) {
                    $values[] = $this->quoteValue($value, isset($binary[$column]));
                }
                $zeile = '(' . implode(', ', $values) . ')';
                $batch[] = $zeile;
                $bytes += strlen($zeile);

                if (count($batch) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                    $this->writeInsert($stream, $table, $columns, $batch);
                    $batch = [];
                    $bytes = 0;
                }
            }
            $select->closeCursor();
        } finally {
            $this->rowsUnbuffered(false);
        }

        if ($batch !== [] && $columns !== null) {
            $this->writeInsert($stream, $table, $columns, $batch);
        }

        fwrite($stream, "\n");
    }

    /**
     * Column names of `$table` whose values are bytes rather than text.
     *
     * @return array<string, true>
     */
    private function binaryColumns(string $table): array
    {
        $binary = [];
        $columns = $this->pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        while (($column = $columns->fetch()) !== false) {
            $type = strtolower((string) ($column['Type'] ?? ''));
            if (preg_match('/\b(?:(?:tiny|medium|long)?blob|(?:var)?binary)\b/', $type) === 1) {
                $binary[(string) $column['Field']] = true;
            }
        }

        return $binary;
    }

    /**
     * Reads the rows of one table without pulling the whole table into
     * memory first. Only MySQL/MariaDB knows the attribute; on any other
     * driver the dump simply stays buffered.
     */
    private function rowsUnbuffered(bool $unbuffered): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, !$unbuffered);
    }

    /**
     * @param resource $stream
     * @param list<string> $batch
     */
    private function writeInsert($stream, string $table, string $columns, array $batch): void
    {
        fwrite($stream, sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $table,
            $columns,
            implode(",\n", $batch),
        ));
    }

    private function quoteValue(mixed $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($binary) {
            // X'' is not accepted everywhere; an empty string is the same
            // thing for a binary column and always is.
            return $value === '' ? "''" : "X'" . bin2hex((string) $value) . "'";
        }

        return $this->pdo->quote((string) $value);
    }
}
