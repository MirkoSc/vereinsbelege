<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What one logged-in account may do, for the length of one request
 * (docs/spec/01-sicherheit.md section 4, issue #19/M3-6): the union of the
 * rights of all roles the account holds, plus the scopes that narrow them.
 *
 * Built fresh on every request by App\Http\LoginGuard's account lookup, the
 * same way the account itself is - changing a role or taking one away takes
 * effect on the next click, not at the next login.
 *
 * Union rule: if two roles grant the same right, the wider scope counts
 * (an account that is both Vorstand and Vereinsverantwortlicher sees the
 * whole inbox). The period scope (`user_scope`) is per account and narrows
 * every right that reads.
 */
final readonly class Berechtigungen
{
    /** @var array<string, PermissionScope> keyed by Permission value */
    private array $rechte;

    /**
     * @param list<Role> $rollen
     * @param list<int> $kostenstellen the account's `user_cost_center` rows
     */
    public function __construct(
        public array $rollen,
        public array $kostenstellen = [],
        public ?\DateTimeImmutable $von = null,
        public ?\DateTimeImmutable $bis = null,
    ) {
        $rechte = [];
        foreach ($rollen as $rolle) {
            foreach ($rolle->rechte() as $wert => $scope) {
                $rechte[$wert] = isset($rechte[$wert]) ? $rechte[$wert]->weiter($scope) : $scope;
            }
        }
        $this->rechte = $rechte;
    }

    public static function keine(): self
    {
        return new self([]);
    }

    public function darf(Permission $recht): bool
    {
        return isset($this->rechte[$recht->value]);
    }

    /**
     * `/admin/*` opens only with at least one `admin.*` right
     * (docs/spec/01-sicherheit.md section 4).
     */
    public function darfAdminBereich(): bool
    {
        foreach (Permission::cases() as $recht) {
            if ($recht->istAdmin() && $this->darf($recht)) {
                return true;
            }
        }

        return false;
    }

    public function scope(Permission $recht): ?PermissionScope
    {
        return $this->rechte[$recht->value] ?? null;
    }

    /**
     * The rows this account may see under one right - what a repository
     * filters by (App\Domain\Zugriffsbereich). Without the right at all,
     * nothing: callers check darf() first, this is the net behind it.
     */
    public function zugriffsbereich(Permission $recht): Zugriffsbereich
    {
        $scope = $this->scope($recht);
        if ($scope === null) {
            return new Zugriffsbereich(kostenstellen: []);
        }

        return new Zugriffsbereich(
            kostenstellen: $scope === PermissionScope::Kostenstelle ? $this->kostenstellen : null,
            von: $this->von,
            bis: $this->bis,
        );
    }

    /** Whether any of the account's roles is an external one. */
    public function istExtern(): bool
    {
        foreach ($this->rollen as $rolle) {
            if ($rolle->extern) {
                return true;
            }
        }

        return false;
    }
}
