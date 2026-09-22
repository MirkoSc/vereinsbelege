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
 *   always on. The end date itself is enforced where every login and every
 *   request is: App\Domain\User::mayLogIn(), via App\Http\LoginGuard.
 * - External roles are not mixed with internal ones: "ausschließlich
 *   lesend" would not hold otherwise.
 * - The last account that can manage accounts (`admin.users`) keeps that
 *   right - nobody locks the club out of its own administration.
 *
 * The page that calls this for a chosen account is the user management of
 * M3-7; the installer (App\Installer\FirstAdminSetup) and the tests use it
 * directly until then.
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
     * @param \DateTimeImmutable|null $ablauf end date of an external account;
     *        null = the default of 60 days. Ignored for internal accounts,
     *        whose end date is cleared.
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

        $ablaufdatum = null;
        if ($extern !== []) {
            $ablaufdatum = $ablauf ?? $now->modify('+' . self::EXTERN_STANDARD_TAGE . ' days');
            if ($ablaufdatum <= $now) {
                throw new RoleRuleViolation('Das Ablaufdatum muss in der Zukunft liegen.');
            }
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
     * Extends (or shortens) an external account's access. Only for
     * external accounts - a regular account has no end date to move.
     *
     * @throws RoleRuleViolation
     */
    public function verlaengern(int $userId, \DateTimeImmutable $bis, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->zugriff->berechtigungen($userId)->istExtern()) {
            throw new RoleRuleViolation('Nur externe Zugänge haben ein Ablaufdatum.');
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
