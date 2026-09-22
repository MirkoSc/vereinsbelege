<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Berechtigungen;

/**
 * The scopes of one account (migrations/010_role.sql): its cost centers
 * (`user_cost_center`) and its period (`user_scope`), and the whole of its
 * rights put together (App\Domain\Berechtigungen) - what App\Http\LoginGuard
 * loads on every request.
 */
final readonly class UserAccessRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function berechtigungen(int $userId): Berechtigungen
    {
        $zeitraum = $this->scope($userId);

        return new Berechtigungen(
            new RoleRepository($this->pdo)->forUser($userId),
            $this->costCenters($userId),
            $zeitraum['von'],
            $zeitraum['bis'],
        );
    }

    /**
     * @return list<int>
     */
    public function costCenters(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT cost_center_id FROM user_cost_center WHERE user_id = ? ORDER BY cost_center_id');
        $stmt->execute([$userId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $costCenterIds replaces the account's list as a whole
     */
    public function setCostCenters(int $userId, array $costCenterIds): void
    {
        $this->pdo->prepare('DELETE FROM user_cost_center WHERE user_id = ?')->execute([$userId]);
        $stmt = $this->pdo->prepare('INSERT INTO user_cost_center (user_id, cost_center_id) VALUES (?, ?)');
        foreach (array_unique($costCenterIds) as $id) {
            $stmt->execute([$userId, $id]);
        }
    }

    /**
     * @return array{von: ?\DateTimeImmutable, bis: ?\DateTimeImmutable}
     */
    public function scope(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT period_from, period_to FROM user_scope WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return [
            'von' => is_array($row) && $row['period_from'] !== null ? new \DateTimeImmutable((string) $row['period_from']) : null,
            'bis' => is_array($row) && $row['period_to'] !== null ? new \DateTimeImmutable((string) $row['period_to']) : null,
        ];
    }

    /** Both null removes the restriction. */
    public function setScope(int $userId, ?\DateTimeImmutable $von, ?\DateTimeImmutable $bis): void
    {
        if ($von === null && $bis === null) {
            $this->pdo->prepare('DELETE FROM user_scope WHERE user_id = ?')->execute([$userId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_scope (user_id, period_from, period_to) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE period_from = VALUES(period_from), period_to = VALUES(period_to)',
        );
        $stmt->execute([$userId, $von?->format('Y-m-d'), $bis?->format('Y-m-d')]);
    }
}
