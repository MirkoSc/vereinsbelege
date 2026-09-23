<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Cost centers (`cost_center`, migrations/010_role.sql). Read-only for now:
 * the user management (issue #20/M3-7) offers them for the cost-center
 * scope; maintaining them is M4-1.
 */
final readonly class CostCenterRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, string> id => name, active ones in display order
     */
    public function active(): array
    {
        $namen = [];
        foreach ($this->pdo->query('SELECT id, name FROM cost_center WHERE active = 1 ORDER BY sort, name')->fetchAll() as $row) {
            $namen[(int) $row['id']] = (string) $row['name'];
        }

        return $namen;
    }
}
