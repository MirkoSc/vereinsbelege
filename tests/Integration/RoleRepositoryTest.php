<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\SystemRole;
use App\Installer\FirstAdminSetup;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Account\RoleRuleViolation;
use App\Service\Account\RoleService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Roles against the real schema (migrations/010_role.sql, docs/spec/
 * 01-sicherheit.md section 4, issue #19/M3-6): the seed, the CRUD and the
 * rules RoleService keeps.
 */
final class RoleRepositoryTest extends DatabaseTestCase
{
    private RoleRepository $rollen;

    private RoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->rollen = new RoleRepository($this->pdo());
        $this->service = new RoleService($this->rollen);
    }

    public function testTheSixSystemRolesAreSeeded(): void
    {
        $system = array_values(array_filter($this->rollen->all(), static fn($r): bool => $r->istSystem()));

        self::assertSame(
            SystemRole::cases(),
            array_map(static fn($r): ?SystemRole => $r->system, $system),
        );
        foreach ($system as $rolle) {
            assert($rolle->system !== null);
            self::assertEquals($rolle->system->standardRechte(), $rolle->rechte(), $rolle->name);
            self::assertSame($rolle->system->istExtern(), $rolle->extern, $rolle->name);
        }
    }

    /**
     * The installer's account from before this migration keeps every right
     * it had (docs/spec/02-datenmodell.md: "weist der bestehenden Zeile die
     * Admin-Rolle zu").
     */
    public function testTheMigrationMakesExistingAccountsAdmins(): void
    {
        // Start over from an empty database - setUp() already migrated all.
        parent::setUp();
        $bis009 = sys_get_temp_dir() . '/vb-migrationen-' . bin2hex(random_bytes(4));
        mkdir($bis009);
        try {
            foreach (glob($this->migrationsDir() . '/*.sql') ?: [] as $datei) {
                if ((int) basename($datei) <= 9) {
                    copy($datei, $bis009 . '/' . basename($datei));
                }
            }
            new Migrator($this->pdo(), $bis009)->migrate();
            $id = $this->benutzer();

            new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        } finally {
            array_map(unlink(...), glob($bis009 . '/*') ?: []);
            rmdir($bis009);
        }

        $rollen = new RoleRepository($this->pdo())->forUser($id);
        self::assertCount(1, $rollen);
        self::assertSame(SystemRole::Admin, $rollen[0]->system);
    }

    public function testTheInstallerGivesTheFirstAccountTheAdminRole(): void
    {
        $ergebnis = new FirstAdminSetup($this->pdo())->create(
            'admin@example.org',
            'Admina',
            'ein-langes-sicheres-passwort',
            base64_encode(str_repeat('s', ServerCrypto::KEY_BYTES)),
        );

        $berechtigungen = new UserAccessRepository($this->pdo())->berechtigungen($ergebnis['userId']);
        foreach (Permission::cases() as $recht) {
            self::assertTrue($berechtigungen->darf($recht), $recht->value);
        }
    }

    public function testACustomRoleRoundTrips(): void
    {
        $id = $this->service->anlegen('  Jugendkasse  ', false, [
            Permission::InboxView->value => PermissionScope::Kostenstelle,
            Permission::DocumentSubmitInternal->value => PermissionScope::Alle,
        ]);

        $rolle = $this->rollen->find($id);
        self::assertNotNull($rolle);
        self::assertSame('Jugendkasse', $rolle->name);
        self::assertFalse($rolle->istSystem());
        self::assertSame(PermissionScope::Kostenstelle, $rolle->scope(Permission::InboxView));
        self::assertSame(PermissionScope::Alle, $rolle->scope(Permission::DocumentSubmitInternal));
        self::assertNull($rolle->scope(Permission::ReportView));

        $this->service->aendern($id, 'Jugendkasse Nord', false, [Permission::ReportView->value => PermissionScope::Alle]);
        $rolle = $this->rollen->find($id);
        self::assertSame('Jugendkasse Nord', $rolle?->name);
        self::assertSame([Permission::ReportView->value => PermissionScope::Alle], $rolle?->rechte());

        $this->service->loeschen($id);
        self::assertNull($this->rollen->find($id));
    }

    public function testUnknownStoredRightsAreDropped(): void
    {
        $id = $this->rollen->create('Aus der Zukunft', false, []);
        $this->pdo()->prepare('UPDATE role SET permissions = ? WHERE id = ?')
            ->execute(['{"inbox.view":"alle","zauberei.wirken":"alle","bank.view":"irgendwie"}', $id]);

        self::assertSame([Permission::InboxView->value => PermissionScope::Alle], $this->rollen->find($id)?->rechte());
    }

    public function testNamesAreRequiredAndUnique(): void
    {
        $this->assertVerletzung(fn() => $this->service->anlegen('   ', false, []), 'Namen');
        $this->assertVerletzung(fn() => $this->service->anlegen('Vorstand', false, []), 'schon');
        $this->assertVerletzung(fn() => $this->service->anlegen(str_repeat('x', RoleService::NAME_MAX + 1), false, []), 'höchstens');
    }

    public function testAdminCannotBeChanged(): void
    {
        $admin = $this->rollen->findSystem(SystemRole::Admin);
        assert($admin !== null);

        $this->assertVerletzung(fn() => $this->service->aendern($admin->id, 'Admin', false, []), 'Admin');
    }

    public function testSystemRolesKeepNameAndKindButRightsCanBeAdjusted(): void
    {
        $vorstand = $this->rollen->findSystem(SystemRole::Vorstand);
        assert($vorstand !== null);

        $this->service->aendern($vorstand->id, 'Präsidium', true, [Permission::ReportView->value => PermissionScope::Alle]);

        $danach = $this->rollen->find($vorstand->id);
        self::assertSame('Vorstand', $danach?->name);
        self::assertFalse($danach?->extern);
        self::assertSame([Permission::ReportView->value => PermissionScope::Alle], $danach?->rechte());
    }

    public function testSystemRolesCannotBeDeleted(): void
    {
        foreach (SystemRole::cases() as $system) {
            $rolle = $this->rollen->findSystem($system);
            assert($rolle !== null);
            $this->assertVerletzung(fn() => $this->service->loeschen($rolle->id), 'Mitgelieferte');
        }
        // Not even through the repository directly.
        $finanzen = $this->rollen->findSystem(SystemRole::Finanzen);
        assert($finanzen !== null);
        $this->rollen->delete($finanzen->id);
        self::assertNotNull($this->rollen->find($finanzen->id));
    }

    public function testExternalRolesMayOnlyRead(): void
    {
        $this->assertVerletzung(
            fn() => $this->service->anlegen('Revision', true, [Permission::DocumentEdit->value => PermissionScope::Alle]),
            'nur lesen',
        );
        $kassenpruefer = $this->rollen->findSystem(SystemRole::Kassenpruefer);
        assert($kassenpruefer !== null);
        $this->assertVerletzung(
            fn() => $this->service->aendern($kassenpruefer->id, '', false, [Permission::BankImport->value => PermissionScope::Alle]),
            'nur lesen',
        );

        $id = $this->service->anlegen('Revision', true, [Permission::ExportZip->value => PermissionScope::Alle]);
        self::assertTrue($this->rollen->find($id)?->extern);
    }

    public function testOnlyCostCenterCapableRightsCanBeScoped(): void
    {
        $this->assertVerletzung(
            fn() => $this->service->anlegen('Seltsam', false, [Permission::BankView->value => PermissionScope::Kostenstelle]),
            'Kostenstellen',
        );
    }

    public function testAnAssignedRoleIsNotDeleted(): void
    {
        $id = $this->service->anlegen('Platzwart', false, [Permission::DocumentSubmitInternal->value => PermissionScope::Alle]);
        $this->rollen->assignToUser($this->benutzer(), [$id]);

        $this->assertVerletzung(fn() => $this->service->loeschen($id), 'zugewiesen');
        self::assertNotNull($this->rollen->find($id));
        self::assertSame([$id => 1], array_intersect_key($this->rollen->userCounts(), [$id => true]));
    }

    public function testAnAssignedRoleDoesNotSwitchBetweenInternalAndExternal(): void
    {
        $id = $this->service->anlegen('Prüfung', false, [Permission::ReportView->value => PermissionScope::Alle]);
        $this->rollen->assignToUser($this->benutzer(), [$id]);

        $this->assertVerletzung(fn() => $this->service->aendern($id, 'Prüfung', true, [Permission::ReportView->value => PermissionScope::Alle]), 'Extern');
    }

    /** Nobody takes the last `admin.users` away - it could never come back. */
    public function testTheLastAccountManagerKeepsTheRight(): void
    {
        $id = $this->service->anlegen('Verwaltung', false, [Permission::AdminUsers->value => PermissionScope::Alle]);
        $this->rollen->assignToUser($this->benutzer(), [$id]);

        $this->assertVerletzung(fn() => $this->service->aendern($id, 'Verwaltung', false, []), 'Mindestens');

        // With an Admin around, it may go.
        $admin = $this->rollen->findSystem(SystemRole::Admin);
        assert($admin !== null);
        $this->rollen->assignToUser($this->benutzer(), [$admin->id]);
        $this->service->aendern($id, 'Verwaltung', false, []);
        self::assertSame([], $this->rollen->find($id)?->rechte());
    }

    // ----------------------------------------------------------- scaffolding

    private function benutzer(): int
    {
        return new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash');
    }

    private function assertVerletzung(\Closure $aktion, string $teil): void
    {
        try {
            $aktion();
        } catch (RoleRuleViolation $e) {
            self::assertStringContainsString($teil, $e->getMessage());

            return;
        }

        self::fail('Expected a RoleRuleViolation containing "' . $teil . '".');
    }
}
