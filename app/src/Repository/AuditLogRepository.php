<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AuditEntry;
use App\Service\Audit\AuditConflict;
use App\Service\Audit\AuditFilter;

/**
 * The `audit_log` table (migrations/011_audit_log.sql, docs/spec/
 * 01-sicherheit.md section 6). Append-only by construction: there is an
 * insert and there are reads - no update, no delete. The chain rules live
 * in App\Service\Audit\AuditLog and AuditChain.
 */
final readonly class AuditLogRepository
{
    /** MySQL/MariaDB "Duplicate entry" - two writers took the same id. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private \PDO $pdo)
    {
    }

    /** The newest row - the one the next row chains to. */
    public function head(): ?AuditEntry
    {
        $row = $this->pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function find(int $id): ?AuditEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @throws AuditConflict when the id is taken - another request appended
     *         first; the caller re-reads the head and tries again
     */
    public function insert(AuditEntry $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log
                (id, ts, user_id, action, entity, entity_id, ip_hash, details_enc, dek_sealed, prev_hash, hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $entry->id, \PDO::PARAM_INT);
        $stmt->bindValue(2, $entry->ts);
        $stmt->bindValue(3, $entry->userId, $entry->userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, $entry->action);
        $stmt->bindValue(5, $entry->entity, $entry->entity === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(6, $entry->entityId, $entry->entityId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(7, $entry->ipHash, $entry->ipHash === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(8, $entry->detailsEnc, $entry->detailsEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(9, $entry->dekSealed, $entry->dekSealed === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(10, $entry->prevHash, \PDO::PARAM_LOB);
        $stmt->bindValue(11, $entry->hash, \PDO::PARAM_LOB);

        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_KEY) {
                throw new AuditConflict('The audit log id is already taken.', previous: $e);
            }
            throw $e;
        }
    }

    /**
     * One page of the list, newest first. Keyset paging: `$vorId` is the
     * smallest id of the previous page, so a page stays stable while new
     * rows arrive on top.
     *
     * @return list<AuditEntry>
     */
    public function page(AuditFilter $filter, ?int $vorId, int $limit): array
    {
        $bedingungen = [];
        $werte = [];

        if ($vorId !== null) {
            $bedingungen[] = 'id < ?';
            $werte[] = $vorId;
        }
        if ($filter->aktion !== null) {
            $bedingungen[] = 'action = ?';
            $werte[] = $filter->aktion->value;
        }
        if ($filter->userId !== null) {
            $bedingungen[] = 'user_id = ?';
            $werte[] = $filter->userId;
        }
        if ($filter->von !== null) {
            $bedingungen[] = 'ts >= ?';
            $werte[] = $filter->von->format('Y-m-d 00:00:00');
        }
        if ($filter->bis !== null) {
            $bedingungen[] = 'ts < ?';
            $werte[] = $filter->bis->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        if ($filter->entity !== null) {
            $bedingungen[] = 'entity = ?';
            $werte[] = $filter->entity;
        }
        if ($filter->entityId !== null) {
            $bedingungen[] = 'entity_id = ?';
            $werte[] = $filter->entityId;
        }

        $sql = 'SELECT * FROM audit_log'
            . ($bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen))
            . sprintf(' ORDER BY id DESC LIMIT %d', max(1, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($werte);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Rows after `$id` in chain order - one stretch of the integrity check.
     *
     * @return list<AuditEntry>
     */
    public function after(int $id, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM audit_log WHERE id > ? ORDER BY id LIMIT %d', max(1, $limit)));
        $stmt->execute([$id]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AuditEntry
    {
        return new AuditEntry(
            id: (int) $row['id'],
            ts: (string) $row['ts'],
            userId: $row['user_id'] === null ? null : (int) $row['user_id'],
            action: (string) $row['action'],
            entity: $row['entity'] === null ? null : (string) $row['entity'],
            entityId: $row['entity_id'] === null ? null : (int) $row['entity_id'],
            ipHash: $row['ip_hash'] === null ? null : (string) $row['ip_hash'],
            detailsEnc: $row['details_enc'] === null ? null : (string) $row['details_enc'],
            dekSealed: $row['dek_sealed'] === null ? null : (string) $row['dek_sealed'],
            prevHash: (string) $row['prev_hash'],
            hash: (string) $row['hash'],
        );
    }
}
