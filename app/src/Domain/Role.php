<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `role` (migrations/010_role.sql, docs/spec/01-sicherheit.md
 * section 4): a name and a set of rights, each either for everything or only
 * for the user's own cost centers.
 *
 * Plaintext by design (docs/spec/02-datenmodell.md): a role carries no club
 * data, and the guard needs it before any vault is involved.
 */
final readonly class Role
{
    /**
     * @param array<string, PermissionScope> $rechte keyed by Permission value
     *        as stored; unknown keys (a newer release's rights) are dropped by
     *        the repository before they arrive here
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?SystemRole $system,
        public bool $extern,
        private array $rechte,
    ) {
    }

    public function istSystem(): bool
    {
        return $this->system !== null;
    }

    /**
     * Admin is fixed: every right, including those a later release adds -
     * whatever the stored row says (issue #19, decision "Admin fest").
     */
    public function istAdmin(): bool
    {
        return $this->system === SystemRole::Admin;
    }

    /**
     * @return array<string, PermissionScope> keyed by Permission value, in
     *         the order of Permission::cases()
     */
    public function rechte(): array
    {
        if ($this->istAdmin()) {
            return SystemRole::Admin->standardRechte();
        }

        $geordnet = [];
        foreach (Permission::cases() as $recht) {
            if (isset($this->rechte[$recht->value])) {
                $geordnet[$recht->value] = $this->rechte[$recht->value];
            }
        }

        return $geordnet;
    }

    public function scope(Permission $recht): ?PermissionScope
    {
        return $this->rechte()[$recht->value] ?? null;
    }
}
