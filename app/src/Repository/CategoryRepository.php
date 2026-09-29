<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Category;
use App\Domain\CategoryColor;
use App\Domain\CategoryDirection;

/**
 * The `category` table (migrations/017_category.sql, docs/spec/
 * 02-datenmodell.md "Kategorien"). SQL only - the rules about what may be
 * changed live in App\Service\MasterData\CategoryService.
 *
 * Plaintext: no club data (CLAUDE.md section 5), so no encryption and no
 * session is needed to read or write it.
 */
final readonly class CategoryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Category> in display order (direction, then sort), active
     *         and inactive alike
     */
    public function all(): array
    {
        return array_map(
            self::hydrate(...),
            $this->pdo->query('SELECT * FROM category ORDER BY direction, sort, name')->fetchAll(),
        );
    }

    /**
     * @return list<Category> one direction only, in display order, active and
     *         inactive alike
     */
    public function byDirection(CategoryDirection $direction): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM category WHERE direction = ? ORDER BY sort, name');
        $stmt->execute([$direction->value]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * What a receipt or booking of the given direction may be assigned to:
     * the active categories of that direction plus those marked `beide`.
     * Without a direction, every active category.
     *
     * @return array<int, string> id => name
     */
    public function active(?CategoryDirection $direction = null): array
    {
        if ($direction === null) {
            $stmt = $this->pdo->query('SELECT id, name FROM category WHERE active = 1 ORDER BY sort, name');
        } else {
            $stmt = $this->pdo->prepare('SELECT id, name FROM category WHERE active = 1 AND direction IN (?, ?) ORDER BY sort, name');
            $stmt->execute([$direction->value, CategoryDirection::Beide->value]);
        }

        $namen = [];
        foreach ($stmt->fetchAll() as $row) {
            $namen[(int) $row['id']] = (string) $row['name'];
        }

        return $namen;
    }

    public function find(int $id): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM category WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(string $name, ?int $ausser = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM category WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $ausser ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** New rows sort last within their direction - 10 more than its current highest. */
    public function create(string $name, CategoryDirection $direction, ?CategoryColor $color, string $aiHint): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO category (name, direction, color, sort, active, ai_hint) VALUES (?, ?, ?, ?, 1, ?)');
        $stmt->execute([$name, $direction->value, $color?->value, $this->nextSort($direction), $aiHint]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A changed direction moves the row to the end of its new group, so it
     * never lands between two neighbours by accident of its old `sort`.
     */
    public function update(int $id, string $name, CategoryDirection $direction, ?CategoryColor $color, string $aiHint, bool $active): void
    {
        $bisher = $this->find($id);
        $sort = $bisher !== null && $bisher->direction === $direction ? $bisher->sort : $this->nextSort($direction);

        $stmt = $this->pdo->prepare('UPDATE category SET name = ?, direction = ?, color = ?, ai_hint = ?, active = ?, sort = ? WHERE id = ?');
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $direction->value);
        $stmt->bindValue(3, $color?->value);
        $stmt->bindValue(4, $aiHint);
        $stmt->bindValue(5, $active, \PDO::PARAM_BOOL);
        $stmt->bindValue(6, $sort, \PDO::PARAM_INT);
        $stmt->bindValue(7, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Fails on a foreign key (RESTRICT) if anything still points at this
     * category - the caller (App\Service\MasterData\CategoryService) checks
     * usageCount() first so it can give a German message instead of a
     * PDOException.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM category WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * How often this category is in use - the one place that decides
     * whether it may still be deleted. Today sub-categories and the default
     * category of suppliers (M6-2) point here; every later table with a
     * `category_id` (invoice, bank_transaction, assignment_rule) adds its
     * count here and
     * its foreign key with ON DELETE RESTRICT (docs/spec/02-datenmodell.md
     * "Kategorien").
     */
    public function usageCount(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM category WHERE parent_id = ?)
                  + (SELECT COUNT(*) FROM supplier WHERE default_category_id = ?)',
        );
        $stmt->execute([$id, $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Rewrites `sort` of the given rows in the given order as 10, 20, 30, ...
     * - the same normalisation on every save so gaps from deleted rows never
     * accumulate. The order counts within one direction.
     *
     * @param list<int> $ids every category id of one direction, in the wanted order
     */
    public function setOrder(array $ids): void
    {
        $stmt = $this->pdo->prepare('UPDATE category SET sort = ? WHERE id = ?');
        $sort = 0;
        foreach ($ids as $id) {
            $sort += 10;
            $stmt->execute([$sort, $id]);
        }
    }

    private function nextSort(CategoryDirection $direction): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM category WHERE direction = ?');
        $stmt->execute([$direction->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            name: (string) $row['name'],
            direction: CategoryDirection::from((string) $row['direction']),
            parentId: $row['parent_id'] === null ? null : (int) $row['parent_id'],
            color: $row['color'] === null ? null : CategoryColor::tryFrom((string) $row['color']),
            sort: (int) $row['sort'],
            active: (bool) $row['active'],
            aiHint: (string) $row['ai_hint'],
        );
    }
}
