<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\CostCenter;

/**
 * The `cost_center` table (migrations/010_role.sql, docs/spec/
 * 02-datenmodell.md "Fachdaten"). SQL only - the rules about what may be
 * changed live in App\Service\MasterData\CostCenterService.
 *
 * Plaintext: no club data (CLAUDE.md section 5), so no encryption and no
 * session is needed to read or write it.
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

    /**
     * Like active(), but with any cost center the given account is already
     * assigned to added back in, marked "(inaktiv)" if it was deactivated
     * since - deactivating one must not make an existing assignment vanish
     * silently from the form that saves it (docs/spec/01-sicherheit.md
     * section 4).
     *
     * @return array<int, string> id => name
     */
    public function activeOrAssigned(?int $userId): array
    {
        $namen = $this->active();
        if ($userId === null) {
            return $namen;
        }

        $stmt = $this->pdo->prepare(
            'SELECT cc.id, cc.name FROM cost_center cc
             JOIN user_cost_center ucc ON ucc.cost_center_id = cc.id
             WHERE ucc.user_id = ? AND cc.active = 0
             ORDER BY cc.sort, cc.name',
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $namen[(int) $row['id']] = (string) $row['name'] . ' (inaktiv)';
        }

        return $namen;
    }

    /**
     * @return list<CostCenter> in display order, active and inactive alike
     */
    public function all(): array
    {
        return array_map(
            self::hydrate(...),
            $this->pdo->query('SELECT * FROM cost_center ORDER BY sort, name')->fetchAll(),
        );
    }

    public function find(int $id): ?CostCenter
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cost_center WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(string $name, ?int $ausser = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cost_center WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $ausser ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** New rows sort last - 10 more than the current highest, 10 if there are none yet. */
    public function create(string $name): int
    {
        $sort = (int) $this->pdo->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM cost_center')->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO cost_center (name, sort, active) VALUES (?, ?, 1)');
        $stmt->execute([$name, $sort]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE cost_center SET name = ?, active = ? WHERE id = ?');
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $active, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Fails on the foreign key (RESTRICT, migrations/012_cost_center_restrict.sql
     * and 014_inbox.sql) if any account or receipt still carries this cost center - the caller
     * (App\Service\MasterData\CostCenterService) checks that first so it can
     * give a German message instead of a PDOException.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cost_center WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function userCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_cost_center WHERE cost_center_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Receipts carrying this cost center (`document.cost_center_id`, issue
     * #27/M4-5) - RESTRICT like the account assignment, so deleting one
     * would fail in the schema too.
     */
    public function documentCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document WHERE cost_center_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, int> cost center id => number of accounts assigned
     */
    public function userCounts(): array
    {
        $zahlen = [];
        foreach ($this->pdo->query('SELECT cost_center_id, COUNT(*) AS n FROM user_cost_center GROUP BY cost_center_id')->fetchAll() as $row) {
            $zahlen[(int) $row['cost_center_id']] = (int) $row['n'];
        }

        return $zahlen;
    }

    /**
     * Rewrites `sort` of every row in the given order as 10, 20, 30, ... -
     * the same normalisation on every save so gaps from deleted rows never
     * accumulate.
     *
     * @param list<int> $ids every cost center id, in the wanted order
     */
    public function setOrder(array $ids): void
    {
        $stmt = $this->pdo->prepare('UPDATE cost_center SET sort = ? WHERE id = ?');
        $sort = 0;
        foreach ($ids as $id) {
            $sort += 10;
            $stmt->execute([$sort, $id]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CostCenter
    {
        return new CostCenter(
            id: (int) $row['id'],
            name: (string) $row['name'],
            sort: (int) $row['sort'],
            active: (bool) $row['active'],
        );
    }
}
