<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\AuditEntry;

/**
 * The hash chain of `audit_log` (docs/spec/01-sicherheit.md section 6,
 * issue #21/M3-8): hash = SHA-256(prev_hash || canonical JSON of the row).
 *
 * Pure computation, no database - the writer (AuditLog::record()) and the
 * check (AuditLog::pruefeAbschnitt(), the tests) share exactly this code.
 *
 * What the chain detects: a row whose stored fields no longer give its
 * hash (changed), a row whose prev_hash is not its predecessor's hash
 * (swapped, inserted), and a gap in the ids (deleted). What it cannot
 * detect alone: rows cut off at the END, or a chain rewritten from some
 * row on with freshly computed hashes. Both change the head hash, which is
 * why the check shows it as a control value to be noted outside the
 * database (docs/spec/01-sicherheit.md section 6).
 */
final class AuditChain
{
    /** prev_hash of the very first row. */
    public const string GENESIS = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    /**
     * The hashed form of a row: every stored field except the two hashes,
     * in a fixed key order, binary fields as hex/base64. Changing this
     * breaks every existing chain - it is a storage format.
     */
    public static function canonicalJson(AuditEntry $entry): string
    {
        return json_encode([
            'id' => $entry->id,
            'ts' => $entry->ts,
            'user_id' => $entry->userId,
            'action' => $entry->action,
            'entity' => $entry->entity,
            'entity_id' => $entry->entityId,
            'ip_hash' => $entry->ipHash === null ? null : bin2hex($entry->ipHash),
            'details_enc' => $entry->detailsEnc === null ? null : base64_encode($entry->detailsEnc),
            'dek_sealed' => $entry->dekSealed === null ? null : base64_encode($entry->dekSealed),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return string raw 32 bytes
     */
    public static function hash(string $prevHash, AuditEntry $entry): string
    {
        return hash('sha256', $prevHash . self::canonicalJson($entry), true);
    }

    /**
     * Walks rows in ascending id order, continuing from the row with id
     * `$nachId` and hash `$nachHash` (0 and GENESIS for the start), and
     * stops at the first break.
     *
     * @param iterable<AuditEntry> $rows
     */
    public static function verify(iterable $rows, int $nachId = 0, string $nachHash = self::GENESIS): AuditChainResult
    {
        $geprueft = 0;
        $letzteId = $nachId;
        $letzterHash = $nachHash;

        foreach ($rows as $row) {
            $bruch = match (true) {
                $row->id !== $letzteId + 1 => AuditChainBreak::Luecke,
                !hash_equals($letzterHash, $row->prevHash) => AuditChainBreak::Verkettung,
                !hash_equals(self::hash($row->prevHash, $row), $row->hash) => AuditChainBreak::Inhalt,
                default => null,
            };
            if ($bruch !== null) {
                return new AuditChainResult($geprueft, $letzteId, $letzterHash, $row->id, $bruch);
            }

            ++$geprueft;
            $letzteId = $row->id;
            $letzterHash = $row->hash;
        }

        return new AuditChainResult($geprueft, $letzteId, $letzterHash);
    }
}
