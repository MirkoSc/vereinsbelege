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
    /**
     * The session settings the dump sets once at its top (and resets at its
     * end). RestoreService repeats them on every step after the first: each
     * step is a request with a fresh connection (issue #120).
     */
    public const string SESSION_NAMES = 'SET NAMES utf8mb4';

    public const string SESSION_FOREIGN_KEYS_OFF = 'SET FOREIGN_KEY_CHECKS = 0';

    public const string SESSION_FOREIGN_KEYS_ON = 'SET FOREIGN_KEY_CHECKS = 1';

    private const int ROWS_PER_INSERT = 100;

    /**
     * Rows per SELECT of a table with a primary key - one part of a step
     * (schritt()). With file_blob_chunk that is up to 100 x 256 KiB of
     * ciphertext, read unbuffered and flushed in INSERTs of BYTES_PER_INSERT.
     */
    private const int ROWS_PER_PORTION = 100;

    /**
     * The only tables read in portions across steps. Every other table goes
     * in ONE SELECT, a consistent InnoDB snapshot of it, as before issue
     * #208: tables like supplier_key are rewritten (delete + insert with
     * new ids) on every save, so two portions could see the same unique
     * value twice and the dump would not restore (1062). These two only
     * ever grow or lose whole rows, carry no unique key besides the
     * primary key, and are the ones that get large (backend `db`: the
     * receipts; the audit log over the years).
     */
    public const array TABLES_IN_PORTIONS = ['file_blob_chunk', 'audit_log'];

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
     * The whole dump in one go - for the CLI and the tests, where no request
     * time limit applies. The admin page and the update chain go through
     * schritt() instead (issue #208).
     *
     * @param resource $stream
     */
    public function dump($stream): void
    {
        $stand = null;
        do {
            $stand = $this->schritt($stream, $stand, PHP_INT_MAX, INF);
        } while ($stand['fertig'] !== true);
    }

    /**
     * Writes the next part of the dump and returns where it stopped
     * (issue #208, docs/spec/06-betrieb.md section 2).
     *
     * `$stand` is what the call before returned, or null for the start. It
     * is plain JSON-safe data, so a step chain can keep it between requests.
     * A part is a table's DROP/CREATE, all its rows in one SELECT, or - for
     * the large tables in TABLES_IN_PORTIONS - one portion of its rows, read
     * by primary key from behind the last key the part before wrote - so a step
     * that is run again from the same `$stand` writes the same statements
     * and none of its rows twice or not at all. After every part the budget
     * is checked; at least one part is always written, so the chain moves on.
     *
     * Parts written one after the other with the returned states give the
     * same statements as one uninterrupted dump: the output is restorable
     * in one piece by SqlSplitter and in steps by SqlStatementStream.
     *
     * @param resource $stream
     * @param array<string, mixed>|null $stand
     * @param int $maxBytes stop after a part once this much has been written
     * @param float $maxSekunden stop after a part once this much time has passed
     *
     * @return array<string, mixed> the new state; `fertig` is true once the
     *         dump is complete, `zeilen` counts the rows written so far
     */
    public function schritt($stream, ?array $stand, int $maxBytes, float $maxSekunden): array
    {
        $beginn = hrtime(true);
        $start = (int) ftell($stream);
        if ($stand === null) {
            $this->schreibe($stream, "-- Vereinsbelege DB dump\n"
                . self::SESSION_NAMES . ";\n"
                . self::SESSION_FOREIGN_KEYS_OFF . ";\n\n");
            $stand = [
                'tabellen' => array_values(array_map(
                    strval(...),
                    $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),
                )),
                'tabelle' => 0,
                'kopf' => false,
                'nach' => null,
                'zeilen' => 0,
                'fertig' => false,
            ];
        }
        if ($stand['fertig'] === true) {
            return $stand;
        }

        do {
            $stand = $this->teil($stream, $stand);
            $geschrieben = (int) ftell($stream) - $start;
            $sekunden = (hrtime(true) - $beginn) / 1e9;
        } while ($stand['fertig'] !== true && $geschrieben < $maxBytes && $sekunden < $maxSekunden);

        return $stand;
    }

    /**
     * One part: the head of the current table, one portion of its rows, or
     * the dump's last line.
     *
     * @param resource $stream
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function teil($stream, array $stand): array
    {
        /** @var list<string> $tabellen */
        $tabellen = $stand['tabellen'];
        $index = (int) $stand['tabelle'];
        if ($index >= count($tabellen)) {
            $this->schreibe($stream, self::SESSION_FOREIGN_KEYS_ON . ";\n");

            return [...$stand, 'fertig' => true];
        }
        $table = $tabellen[$index];

        if ($stand['kopf'] !== true) {
            $create = $this->pdo
                ->query(sprintf('SHOW CREATE TABLE `%s`', $table))
                ->fetch();
            $this->schreibe($stream, sprintf("DROP TABLE IF EXISTS `%s`;\n", $table)
                . (string) ($create['Create Table'] ?? '') . ";\n");

            return [...$stand, 'kopf' => true, 'nach' => null];
        }

        // Everything the connection needs to know about the table is asked
        // here: nothing else may travel over it while the unbuffered SELECT
        // below is being read. PDO::quote() is client-side and therefore fine.
        $binary = $this->binaryColumns($table);
        $schluessel = in_array($table, self::TABLES_IN_PORTIONS, true) ? $this->primaryKey($table) : [];

        $nach = is_array($stand['nach']) ? $stand['nach'] : null;
        [$sql, $parameter] = $this->auswahl($table, $schluessel, $binary, $nach);

        $columns = null;
        $batch = [];
        $bytes = 0;
        $anzahl = 0;
        $letzte = null;
        $this->rowsUnbuffered(true);
        try {
            $select = $this->pdo->prepare($sql);
            foreach ($parameter as $i => $wert) {
                $select->bindValue($i + 1, $wert, is_int($wert) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $select->execute();
            while (($row = $select->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $columns ??= '`' . implode('`, `', array_keys($row)) . '`';

                $values = [];
                foreach ($row as $column => $value) {
                    $values[] = $this->quoteValue($value, isset($binary[$column]));
                }
                $zeile = '(' . implode(', ', $values) . ')';
                $batch[] = $zeile;
                $bytes += strlen($zeile);
                $anzahl++;
                $letzte = $row;

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

        // Without portions (TABLES_IN_PORTIONS, or no primary key) the
        // table went in one SELECT. A portion shorter than the limit was
        // the table's last.
        $tabelleFertig = $schluessel === [] || $anzahl < self::ROWS_PER_PORTION;
        if ($tabelleFertig) {
            $this->schreibe($stream, "\n");

            return [
                ...$stand,
                'tabelle' => $index + 1,
                'kopf' => false,
                'nach' => null,
                'zeilen' => (int) $stand['zeilen'] + $anzahl,
            ];
        }

        $nachNeu = [];
        foreach ($schluessel as $spalte) {
            $wert = $letzte[$spalte] ?? null;
            $nachNeu[] = match (true) {
                is_int($wert) => $wert,
                isset($binary[$spalte]) => bin2hex((string) $wert),
                default => (string) $wert,
            };
        }

        return [...$stand, 'nach' => $nachNeu, 'zeilen' => (int) $stand['zeilen'] + $anzahl];
    }

    /**
     * The SELECT of one portion: the rows behind `$nach` in primary key
     * order, or the whole table when it has no primary key.
     *
     * The keyset condition for a key (a, b) reads
     * `a >= ? AND (a > ? OR (a = ? AND b > ?))` - the leading `a >= ?`
     * gives the optimizer a plain range on the primary key, so a portion
     * deep inside file_blob_chunk costs no more than the first one.
     *
     * @param list<string> $schluessel
     * @param array<string, true> $binary
     * @param list<int|string>|null $nach
     * @return array{string, list<int|string>}
     */
    private function auswahl(string $table, array $schluessel, array $binary, ?array $nach): array
    {
        if ($schluessel === []) {
            if ($nach !== null) {
                // The key vanished between two steps: the whole table again
                // would repeat the rows already written.
                throw new \RuntimeException('Der Stand des Datenbank-Dumps passt nicht zur Tabelle.');
            }

            return [sprintf('SELECT * FROM `%s`', $table), []];
        }

        $platzhalter = static fn(string $spalte): string => isset($binary[$spalte]) ? 'UNHEX(?)' : '?';
        $sql = sprintf('SELECT * FROM `%s`', $table);
        $parameter = [];
        if ($nach !== null) {
            if (count($nach) !== count($schluessel)) {
                throw new \RuntimeException('Der Stand des Datenbank-Dumps passt nicht zur Tabelle.');
            }
            $oder = [];
            foreach ($schluessel as $i => $spalte) {
                $teile = [];
                for ($j = 0; $j < $i; $j++) {
                    $teile[] = sprintf('`%s` = %s', $schluessel[$j], $platzhalter($schluessel[$j]));
                    $parameter[] = $nach[$j];
                }
                $teile[] = sprintf('`%s` > %s', $spalte, $platzhalter($spalte));
                $parameter[] = $nach[$i];
                $oder[] = '(' . implode(' AND ', $teile) . ')';
            }
            $sql .= sprintf(' WHERE `%s` >= %s AND (%s)', $schluessel[0], $platzhalter($schluessel[0]), implode(' OR ', $oder));
            array_unshift($parameter, $nach[0]);
        }
        $sql .= ' ORDER BY `' . implode('`, `', $schluessel) . '` LIMIT ' . self::ROWS_PER_PORTION;

        return [$sql, $parameter];
    }

    /**
     * The primary key columns of `$table` in key order, or an empty list.
     *
     * @return list<string>
     */
    private function primaryKey(string $table): array
    {
        $spalten = [];
        $keys = $this->pdo->query(sprintf("SHOW KEYS FROM `%s` WHERE Key_name = 'PRIMARY'", $table));
        while (($key = $keys->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $spalten[(int) $key['Seq_in_index']] = (string) $key['Column_name'];
        }
        ksort($spalten);

        return array_values($spalten);
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
        // The MYSQL_ATTR_* constants on \PDO itself are deprecated since
        // 8.5 in favour of the driver-specific subclass (PHP 8.4+, and the
        // platform minimum here is 8.5 - CLAUDE.md section 1).
        $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, !$unbuffered);
    }

    /**
     * @param resource $stream
     * @param list<string> $batch
     */
    private function writeInsert($stream, string $table, string $columns, array $batch): void
    {
        $this->schreibe($stream, sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $table,
            $columns,
            implode(",\n", $batch),
        ));
    }

    /**
     * fwrite() may write less than asked (a full disk): a dump that silently
     * lacks a piece must never pass for complete.
     *
     * @param resource $stream
     */
    private function schreibe($stream, string $daten): void
    {
        $laenge = strlen($daten);
        $geschrieben = 0;
        while ($geschrieben < $laenge) {
            $n = fwrite($stream, $geschrieben === 0 ? $daten : substr($daten, $geschrieben));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Der Datenbank-Dump konnte nicht geschrieben werden (Speicher voll?).');
            }
            $geschrieben += $n;
        }
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
