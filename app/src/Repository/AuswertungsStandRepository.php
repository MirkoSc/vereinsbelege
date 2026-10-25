<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Report\Datenstand;

/**
 * The fingerprint the report cache checks before every use (issue #72,
 * App\Service\Report\AuswertungsCache): a live checksum over every row of
 * every table report input is built from - receipts and their documents
 * (status), bookings, allocations, accounts, the vault (rotation), budgets.
 *
 * CHECKSUM TABLE covers all columns of all rows, so it changes on every
 * insert, update and delete - including an allocation removed without any
 * updated_at moving, and a re-encryption that keeps every timestamp (each
 * write of a ciphertext has a fresh nonce). No generation counter that a
 * new write path could forget to bump. Cost: one scan of these tables, a
 * few thousand rows for a club - milliseconds. Only checksums of
 * ciphertext and structure; nothing decrypted.
 */
final readonly class AuswertungsStandRepository implements Datenstand
{
    /**
     * Fixed list - table names come from code, never from a request.
     * `budget` (issue #92): the budget page reads it next to the cached
     * report input, so a changed budget also starts afresh.
     */
    public const array TABELLEN = ['document', 'invoice', 'allocation', 'bank_transaction', 'bank_account', 'vault', 'budget'];

    public function __construct(private \PDO $pdo)
    {
    }

    public function fingerabdruck(): ?string
    {
        $stmt = $this->pdo->query('CHECKSUM TABLE ' . implode(', ', self::TABELLEN));
        if ($stmt === false) {
            return null;
        }
        $teile = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $zeile) {
            // NULL = the table is missing (or the engine cannot tell): no
            // reliable state, so no cache.
            if (!isset($zeile[0], $zeile[1])) {
                return null;
            }
            $teile[] = $zeile[0] . '=' . $zeile[1];
        }
        if (count($teile) !== count(self::TABELLEN)) {
            return null;
        }
        sort($teile);

        return hash('sha256', implode('|', $teile));
    }
}
