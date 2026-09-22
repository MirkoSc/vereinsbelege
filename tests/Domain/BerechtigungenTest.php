<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\Domain\SystemRole;
use PHPUnit\Framework\TestCase;

/**
 * An account's rights as the union of its roles, and the scopes that
 * narrow them (docs/spec/01-sicherheit.md section 4, issue #19/M3-6).
 */
final class BerechtigungenTest extends TestCase
{
    private static function system(SystemRole $rolle, int $id = 1): Role
    {
        return new Role($id, $rolle->bezeichnung(), $rolle, $rolle->istExtern(), $rolle->standardRechte());
    }

    public function testWithoutRolesNothingIsAllowed(): void
    {
        $keine = Berechtigungen::keine();

        foreach (Permission::cases() as $recht) {
            self::assertFalse($keine->darf($recht), $recht->value);
        }
        self::assertFalse($keine->darfAdminBereich());
    }

    /** Admin is fixed: every right, whatever its stored row says. */
    public function testAdminHoldsEveryRightEvenIfTheRowWasEmptied(): void
    {
        $admin = new Berechtigungen([new Role(1, 'Admin', SystemRole::Admin, false, [])]);

        foreach (Permission::cases() as $recht) {
            self::assertTrue($admin->darf($recht), $recht->value);
            self::assertSame(PermissionScope::Alle, $admin->scope($recht));
        }
        self::assertTrue($admin->darfAdminBereich());
    }

    public function testRightsOfSeveralRolesAreUnited(): void
    {
        $b = new Berechtigungen([self::system(SystemRole::Vorstand, 1), self::system(SystemRole::Finanzen, 2)]);

        self::assertTrue($b->darf(Permission::AuditView), 'from Vorstand');
        self::assertTrue($b->darf(Permission::DocumentEdit), 'from Finanzen');
        self::assertFalse($b->darf(Permission::AdminUsers));
        self::assertFalse($b->darfAdminBereich());
    }

    /**
     * Vorstand + Vereinsverantwortlicher: the whole inbox, because the wider
     * grant of the same right counts.
     */
    public function testTheWiderScopeWins(): void
    {
        $nurVerantwortlich = new Berechtigungen([self::system(SystemRole::Vereinsverantwortlicher)], [3, 5]);
        $beides = new Berechtigungen([self::system(SystemRole::Vereinsverantwortlicher, 1), self::system(SystemRole::Vorstand, 2)], [3, 5]);

        self::assertSame(PermissionScope::Kostenstelle, $nurVerantwortlich->scope(Permission::InboxView));
        self::assertSame([3, 5], $nurVerantwortlich->zugriffsbereich(Permission::InboxView)->kostenstellen);

        self::assertSame(PermissionScope::Alle, $beides->scope(Permission::InboxView));
        self::assertNull($beides->zugriffsbereich(Permission::InboxView)->kostenstellen);
    }

    public function testAnUnscopedRightOfAScopedRoleReachesEverything(): void
    {
        $b = new Berechtigungen([self::system(SystemRole::Vereinsverantwortlicher)], [3]);

        self::assertNull($b->zugriffsbereich(Permission::DocumentSubmitInternal)->kostenstellen);
    }

    public function testAScopedRoleWithoutCostCentersSeesNothing(): void
    {
        $b = new Berechtigungen([self::system(SystemRole::Vereinsverantwortlicher)]);

        self::assertSame([], $b->zugriffsbereich(Permission::ReportView)->kostenstellen);
        self::assertFalse($b->zugriffsbereich(Permission::ReportView)->erlaubt(3, new \DateTimeImmutable()));
    }

    public function testThePeriodScopeAppliesToEveryRight(): void
    {
        $von = new \DateTimeImmutable('2026-01-01');
        $bis = new \DateTimeImmutable('2026-12-31');
        $b = new Berechtigungen([self::system(SystemRole::Kassenpruefer)], [], $von, $bis);

        foreach ([Permission::InboxView, Permission::BankView, Permission::ReportView] as $recht) {
            $bereich = $b->zugriffsbereich($recht);
            self::assertEquals($von, $bereich->von, $recht->value);
            self::assertEquals($bis, $bereich->bis, $recht->value);
            self::assertNull($bereich->kostenstellen, $recht->value);
        }
    }

    public function testWithoutTheRightTheScopeIsEmpty(): void
    {
        $b = new Berechtigungen([self::system(SystemRole::Steuerberater)]);

        self::assertSame([], $b->zugriffsbereich(Permission::AuditView)->kostenstellen);
    }

    public function testExternalIsKnown(): void
    {
        self::assertTrue(new Berechtigungen([self::system(SystemRole::Steuerberater)])->istExtern());
        self::assertFalse(new Berechtigungen([self::system(SystemRole::Finanzen)])->istExtern());
    }

    /** A custom role with only one admin.* right opens /admin, nothing more. */
    public function testOneAdminRightOpensTheAdminArea(): void
    {
        $b = new Berechtigungen([new Role(9, 'Technik', null, false, [Permission::AdminSystem->value => PermissionScope::Alle])]);

        self::assertTrue($b->darfAdminBereich());
        self::assertTrue($b->darf(Permission::AdminSystem));
        self::assertFalse($b->darf(Permission::AdminUsers));
    }
}
