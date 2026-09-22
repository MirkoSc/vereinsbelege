<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\Repository\RoleRepository;

/**
 * Creating, changing and deleting roles (docs/spec/01-sicherheit.md
 * section 4, issue #19/M3-6) - the rules, not the SQL:
 *
 * - The six system roles can be neither renamed nor deleted. Their rights
 *   can be adjusted, except Admin's: Admin always holds every right
 *   (App\Domain\Role::istAdmin()).
 * - An external role (Kassenprüfer, Steuerberater, or one of the club's own
 *   marked external) may hold reading rights only.
 * - "Nur eigene Kostenstelle" exists only for the rights that can be split
 *   that way (Permission::kostenstellenFaehig()).
 * - `admin.users` cannot be taken away from the last role any account
 *   holds it through.
 * - A role still assigned to somebody is not deleted - taking rights away
 *   has to be a visible decision, not a side effect.
 */
final readonly class RoleService
{
    public const int NAME_MAX = 100;

    public function __construct(private RoleRepository $rollen)
    {
    }

    /**
     * @param array<string, PermissionScope> $rechte keyed by Permission value
     * @throws RoleRuleViolation
     */
    public function anlegen(string $name, bool $extern, array $rechte): int
    {
        $name = $this->pruefeName($name, null);
        $this->pruefeRechte($rechte, $extern);

        return $this->rollen->create($name, $extern, $rechte);
    }

    /**
     * For a system role, $name and $extern are ignored: it keeps both.
     *
     * @param array<string, PermissionScope> $rechte
     * @throws RoleRuleViolation
     */
    public function aendern(int $id, string $name, bool $extern, array $rechte): void
    {
        $rolle = $this->rolle($id);

        if ($rolle->istAdmin()) {
            throw new RoleRuleViolation('Die Rolle „Admin“ hat immer alle Rechte und kann nicht geändert werden.');
        }

        $this->pruefeLetztenAdmin($rolle, $rechte);

        if ($rolle->istSystem()) {
            $this->pruefeRechte($rechte, $rolle->extern);
            $this->rollen->updatePermissions($id, $rechte);

            return;
        }

        $name = $this->pruefeName($name, $id);
        $this->pruefeRechte($rechte, $extern);
        // Switching internal/external under an account would undo what
        // AccessAssignment checked when it gave the role out (end date, 2FA,
        // no mixing) - the role is emptied of accounts first.
        if ($extern !== $rolle->extern && $this->rollen->countUsers($id) > 0) {
            throw new RoleRuleViolation('„Extern“ lässt sich nur bei Rollen ändern, die niemandem zugewiesen sind.');
        }
        $this->rollen->update($id, $name, $extern, $rechte);
    }

    /**
     * @throws RoleRuleViolation
     */
    public function loeschen(int $id): void
    {
        $rolle = $this->rolle($id);

        if ($rolle->istSystem()) {
            throw new RoleRuleViolation('Mitgelieferte Rollen können nicht gelöscht werden.');
        }
        if ($this->rollen->countUsers($id) > 0) {
            throw new RoleRuleViolation('Die Rolle ist noch Benutzern zugewiesen. Bitte zuerst die Zuweisungen entfernen.');
        }

        $this->rollen->delete($id);
    }

    /**
     * Taking `admin.users` away from a role must leave somebody who still
     * holds it - otherwise nobody could ever give it back.
     *
     * @param array<string, PermissionScope> $rechte
     */
    private function pruefeLetztenAdmin(Role $rolle, array $rechte): void
    {
        if ($rolle->scope(Permission::AdminUsers) === null || isset($rechte[Permission::AdminUsers->value])) {
            return;
        }

        foreach ($this->rollen->all() as $andere) {
            if ($andere->id !== $rolle->id && $andere->scope(Permission::AdminUsers) !== null && $this->rollen->countUsers($andere->id) > 0) {
                return;
            }
        }

        throw new RoleRuleViolation('Mindestens ein Benutzer muss Benutzer und Rollen verwalten dürfen.');
    }

    private function rolle(int $id): Role
    {
        return $this->rollen->find($id) ?? throw new RoleRuleViolation('Diese Rolle gibt es nicht.');
    }

    private function pruefeName(string $name, ?int $id): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RoleRuleViolation('Bitte einen Namen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RoleRuleViolation(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        if ($this->rollen->nameExists($name, $id)) {
            throw new RoleRuleViolation('Eine Rolle mit diesem Namen gibt es schon.');
        }

        return $name;
    }

    /**
     * @param array<string, PermissionScope> $rechte
     */
    private function pruefeRechte(array $rechte, bool $extern): void
    {
        foreach ($rechte as $wert => $scope) {
            $recht = Permission::tryFrom($wert) ?? throw new RoleRuleViolation('Unbekanntes Recht.');

            if ($scope === PermissionScope::Kostenstelle && !$recht->kostenstellenFaehig()) {
                throw new RoleRuleViolation(sprintf('„%s“ lässt sich nicht auf Kostenstellen beschränken.', $recht->bezeichnung()));
            }
            if ($extern && !$recht->istLesend()) {
                throw new RoleRuleViolation(sprintf('Externe Rollen dürfen nur lesen – „%s“ ist nicht erlaubt.', $recht->bezeichnung()));
            }
        }
    }
}
