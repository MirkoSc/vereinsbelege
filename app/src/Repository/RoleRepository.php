<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\Domain\SystemRole;

/**
 * The `role` and `user_role` tables (migrations/010_role.sql, docs/spec/
 * 01-sicherheit.md section 4). SQL only - the rules about what may be
 * changed live in App\Service\Account\RoleService.
 */
final readonly class RoleRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Role> system roles first, in the order of the spec's
     *         matrix, then the club's own by name
     */
    public function all(): array
    {
        $rollen = array_map(
            self::hydrate(...),
            $this->pdo->query('SELECT * FROM role ORDER BY is_system DESC, id')->fetchAll(),
        );

        $system = array_values(array_filter($rollen, static fn(Role $r): bool => $r->istSystem()));
        $eigene = array_values(array_filter($rollen, static fn(Role $r): bool => !$r->istSystem()));
        usort($eigene, static fn(Role $a, Role $b): int => strcasecmp($a->name, $b->name));

        return [...$system, ...$eigene];
    }

    public function find(int $id): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT * FROM role WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function findSystem(SystemRole $rolle): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT * FROM role WHERE system_key = ?');
        $stmt->execute([$rolle->value]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(string $name, ?int $ausser = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM role WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $ausser ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, PermissionScope> $rechte keyed by Permission value
     */
    public function create(string $name, bool $extern, array $rechte, ?\DateTimeImmutable $now = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role (system_key, name, is_system, is_external, permissions, created_at)
             VALUES (NULL, ?, 0, ?, ?, ?)',
        );
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $extern, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, self::encode($rechte));
        $stmt->bindValue(4, ($now ?? new \DateTimeImmutable())->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Name and external flag change only for the club's own roles - the
     * WHERE keeps a system role's name and kind even if a caller forgot to.
     *
     * @param array<string, PermissionScope> $rechte
     */
    public function update(int $id, string $name, bool $extern, array $rechte): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE role SET name = ?, is_external = ?, permissions = ? WHERE id = ? AND is_system = 0',
        );
        $stmt->bindValue(1, $name);
        $stmt->bindValue(2, $extern, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, self::encode($rechte));
        $stmt->bindValue(4, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array<string, PermissionScope> $rechte
     */
    public function updatePermissions(int $id, array $rechte): void
    {
        $stmt = $this->pdo->prepare('UPDATE role SET permissions = ? WHERE id = ?');
        $stmt->execute([self::encode($rechte), $id]);
    }

    /** System roles are never deleted, whoever asks. */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM role WHERE id = ? AND is_system = 0');
        $stmt->execute([$id]);
    }

    public function countUsers(int $roleId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_role WHERE role_id = ?');
        $stmt->execute([$roleId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, int> role id => number of accounts holding it
     */
    public function userCounts(): array
    {
        $zahlen = [];
        foreach ($this->pdo->query('SELECT role_id, COUNT(*) AS n FROM user_role GROUP BY role_id')->fetchAll() as $row) {
            $zahlen[(int) $row['role_id']] = (int) $row['n'];
        }

        return $zahlen;
    }

    /**
     * @return list<Role>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.* FROM role r JOIN user_role ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.id',
        );
        $stmt->execute([$userId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Replaces the account's roles as a whole.
     *
     * @param list<int> $roleIds
     */
    public function assignToUser(int $userId, array $roleIds): void
    {
        $this->pdo->prepare('DELETE FROM user_role WHERE user_id = ?')->execute([$userId]);
        $stmt = $this->pdo->prepare('INSERT INTO user_role (user_id, role_id) VALUES (?, ?)');
        foreach (array_unique($roleIds) as $roleId) {
            $stmt->execute([$userId, $roleId]);
        }
    }

    /**
     * @param array<string, PermissionScope> $rechte
     */
    private static function encode(array $rechte): string
    {
        return json_encode(
            array_map(static fn(PermissionScope $s): string => $s->value, $rechte),
            JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT,
        );
    }

    /**
     * Keys a newer release wrote and this one does not know are dropped, and
     * so is an unknown scope - a right this code cannot name is a right it
     * does not grant.
     *
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Role
    {
        $gespeichert = json_decode((string) $row['permissions'], true, 4, JSON_THROW_ON_ERROR);
        $rechte = [];
        foreach (is_array($gespeichert) ? $gespeichert : [] as $wert => $scope) {
            $recht = Permission::tryFrom((string) $wert);
            $reichweite = is_string($scope) ? PermissionScope::tryFrom($scope) : null;
            if ($recht !== null && $reichweite !== null) {
                $rechte[$recht->value] = $reichweite;
            }
        }

        return new Role(
            id: (int) $row['id'],
            name: (string) $row['name'],
            system: $row['system_key'] === null ? null : SystemRole::tryFrom((string) $row['system_key']),
            extern: (bool) $row['is_external'],
            rechte: $rechte,
        );
    }
}
