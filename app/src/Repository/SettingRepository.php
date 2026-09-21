<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `setting` table: non-sensitive key/value pairs (CLAUDE.md section 5,
 * docs/spec/02-datenmodell.md). SQL lives in repositories only, prepared
 * statements only, no query builder.
 *
 * Values are plaintext, so nothing business related may be stored here -
 * the first and so far only entry is the update channel.
 */
final readonly class SettingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function get(string $name, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM setting WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function set(string $name, string $value): void
    {
        // Timestamp from PHP, not NOW(): the database session may run in UTC
        // while the application convention is Europe/Berlin (bootstrap).
        $stmt = $this->pdo->prepare(
            'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
        );
        $stmt->execute([$name, $value, new \DateTimeImmutable()->format('Y-m-d H:i:s')]);
    }
}
