<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Permission;
use App\Domain\Role;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;

/**
 * Gives an account its roles and scopes (docs/spec/01-sicherheit.md
 * section 4, issue #19/M3-6) and holds the rules for external accounts:
 *
 * - An account with an external role (Kassenprüfer, Steuerberater) gets an
 *   end date - 60 days unless one is given, extendable - and `mfa_required`
 *   always on. An internal account MAY have one (a helper for one season,
 *   issue #20/M3-7), it does not need one. The end date itself is enforced
 *   where every login and every request is: App\Domain\User::mayLogIn(),
 *   via App\Http\LoginGuard.
 * - External roles are not mixed with internal ones: "ausschließlich
 *   lesend" would not hold otherwise.
 * - The last account that can manage accounts (`admin.users`) keeps that
 *   right - nobody locks the club out of its own administration.
 *
 * Called by the user management (App\Admin\UserController, App\Service\
 * Account\Invitation, M3-7).
 */
final readonly class AccessAssignment
{
    public const int EXTERN_STANDARD_TAGE = 60;

    public function __construct(
        private \PDO $pdo,
        private RoleRepository $rollen,
        private UserAccessRepository $zugriff,
        private UserRepository $benutzer,
    ) {
    }

    /**
     * @param list<int> $roleIds
     * @param list<int> $kostenstellen cost centers for rights with scope
     *        `kostenstelle`
     * @param \DateTimeImmutable|null $ablauf end date of the account. For an
     *        external account null means the default of 60 days; for an
     *        internal one null means none.
     * @throws RoleRuleViolation
     */
    public function zuweisen(
        int $userId,
        array $roleIds,
        array $kostenstellen = [],
        ?\DateTimeImmutable $von = null,
        ?\DateTimeImmutable $bis = null,
        ?\DateTimeImmutable $ablauf = null,
        ?\DateTimeImmutable $now = null,
    ): void {
        $now ??= new \DateTimeImmutable();
        if ($this->benutzer->findById($userId) === null) {
            throw new RoleRuleViolation('Diesen Benutzer gibt es nicht.');
        }

        $rollen = [];
        foreach (array_unique($roleIds) as $id) {
            $rollen[] = $this->rollen->find($id) ?? throw new RoleRuleViolation('Diese Rolle gibt es nicht.');
        }

        $extern = array_filter($rollen, static fn(Role $r): bool => $r->extern);
        if ($extern !== [] && count($extern) !== count($rollen)) {
            throw new RoleRuleViolation('Externe Rollen (z. B. Kassenprüfer, Steuerberater) lassen sich nicht mit internen Rollen kombinieren.');
        }
        if ($von !== null && $bis !== null && $von > $bis) {
            throw new RoleRuleViolation('Der Zeitraum endet vor seinem Beginn.');
        }

        $ablaufdatum = $extern !== []
            ? $ablauf ?? $now->modify('+' . self::EXTERN_STANDARD_TAGE . ' days')
            : $ablauf;
        if ($ablaufdatum !== null && $ablaufdatum <= $now) {
            throw new RoleRuleViolation('Das Ablaufdatum muss in der Zukunft liegen.');
        }

        $verwalterVorher = $this->gibtVerwalter();

        $this->pdo->beginTransaction();
        try {
            $this->rollen->assignToUser($userId, array_map(static fn(Role $r): int => $r->id, $rollen));
            if ($verwalterVorher && !$this->gibtVerwalter()) {
                throw new RoleRuleViolation('Mindestens ein Benutzer muss Benutzer und Rollen verwalten dürfen.');
            }

            $this->zugriff->setCostCenters($userId, $kostenstellen);
            $this->zugriff->setScope($userId, $von, $bis);
            $this->benutzer->updateExpiry($userId, $ablaufdatum);
            if ($extern !== []) {
                $this->benutzer->updateMfaRequired($userId, true);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Extends (or shortens) an account's access - external or, since M3-7,
     * internal (issue #20).
     *
     * @throws RoleRuleViolation
     */
    public function verlaengern(int $userId, \DateTimeImmutable $bis, ?\DateTimeImmutable $now = null): void
    {
        if ($this->benutzer->findById($userId) === null) {
            throw new RoleRuleViolation('Diesen Benutzer gibt es nicht.');
        }
        if ($bis <= ($now ?? new \DateTimeImmutable())) {
            throw new RoleRuleViolation('Das Ablaufdatum muss in der Zukunft liegen.');
        }

        $this->benutzer->updateExpiry($userId, $bis);
    }

    /** Whether any account holds `admin.users` through any of its roles. */
    private function gibtVerwalter(): bool
    {
        foreach ($this->rollen->all() as $rolle) {
            if ($rolle->scope(Permission::AdminUsers) !== null && $this->rollen->countUsers($rolle->id) > 0) {
                return true;
            }
        }

        return false;
    }
}
