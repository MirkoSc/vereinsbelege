<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Permission;
use App\Domain\SystemRole;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Zugriff;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Account\AccessAssignment;
use App\Service\Account\RoleRuleViolation;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Cost-center scope, period scope and the end date of external accounts
 * against the real schema (docs/spec/01-sicherheit.md section 4,
 * "Pflicht-Tests", issue #19/M3-6).
 *
 * The business tables arrive from M4 on. Until then the scope runs against
 * a probe table with the same column shapes a receipt will have
 * (`cost_center_id` with a foreign key, a DATE column) - what is tested is
 * that the filter happens in SQL, where the spec wants it ("im Repository
 * erzwungen"), and that it lets through exactly the right rows.
 */
final class AccessScopeTest extends DatabaseTestCase
{
    private RoleRepository $rollen;

    private UserAccessRepository $zugriff;

    private UserRepository $benutzer;

    private AccessAssignment $zuweisung;

    /** @var array<string, int> cost center name => id */
    private array $kostenstellen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->rollen = new RoleRepository($this->pdo());
        $this->zugriff = new UserAccessRepository($this->pdo());
        $this->benutzer = new UserRepository($this->pdo());
        $this->zuweisung = new AccessAssignment($this->pdo(), $this->rollen, $this->zugriff, $this->benutzer);

        foreach (['Herren', 'E-Jugend', 'Vereinsheim'] as $sort => $name) {
            $this->pdo()->prepare('INSERT INTO cost_center (name, sort) VALUES (?, ?)')->execute([$name, $sort]);
            $this->kostenstellen[$name] = (int) $this->pdo()->lastInsertId();
        }

        $this->pdo()->exec(
            'CREATE TABLE scope_probe (
                id INT NOT NULL PRIMARY KEY,
                cost_center_id BIGINT UNSIGNED NULL,
                beleg_datum DATE NOT NULL,
                CONSTRAINT fk_scope_probe_cc FOREIGN KEY (cost_center_id) REFERENCES cost_center (id)
            ) ENGINE=InnoDB',
        );
        $zeilen = [
            [1, 'Herren', '2025-12-31'],
            [2, 'Herren', '2026-01-01'],
            [3, 'E-Jugend', '2026-06-15'],
            [4, 'Vereinsheim', '2026-12-31'],
            [5, null, '2026-03-01'],
            [6, 'E-Jugend', '2027-01-01'],
        ];
        $stmt = $this->pdo()->prepare('INSERT INTO scope_probe (id, cost_center_id, beleg_datum) VALUES (?, ?, ?)');
        foreach ($zeilen as [$id, $kostenstelle, $datum]) {
            $stmt->execute([$id, $kostenstelle === null ? null : $this->kostenstellen[$kostenstelle], $datum]);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------ cost-center scope

    public function testAVereinsverantwortlicherSeesOnlyTheirCostCenters(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vereinsverantwortlicher)], [$this->kostenstellen['E-Jugend']]);

        $b = $this->zugriff->berechtigungen($id);

        self::assertSame([3, 6], $this->sichtbar($id, Permission::InboxView));
        self::assertSame([3, 6], $this->sichtbar($id, Permission::ReportView));
        self::assertFalse($b->darf(Permission::BankView));
    }

    public function testSeveralCostCenters(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen(
            $id,
            [$this->rolle(SystemRole::Vereinsverantwortlicher)],
            [$this->kostenstellen['Herren'], $this->kostenstellen['Vereinsheim']],
        );

        self::assertSame([1, 2, 4], $this->sichtbar($id, Permission::InboxView));
    }

    public function testWithoutAssignedCostCentersNothingIsVisible(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vereinsverantwortlicher)]);

        self::assertSame([], $this->sichtbar($id, Permission::InboxView));
    }

    public function testAnUnscopedRoleSeesEverything(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vorstand)], [$this->kostenstellen['Herren']]);

        self::assertSame([1, 2, 3, 4, 5, 6], $this->sichtbar($id, Permission::InboxView));
    }

    /** Deleting a cost center takes it out of every scope with it. */
    public function testADeletedCostCenterLeavesTheScope(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vereinsverantwortlicher)], [$this->kostenstellen['Vereinsheim']]);

        $this->pdo()->prepare('DELETE FROM scope_probe WHERE cost_center_id = ?')->execute([$this->kostenstellen['Vereinsheim']]);
        $this->pdo()->prepare('DELETE FROM cost_center WHERE id = ?')->execute([$this->kostenstellen['Vereinsheim']]);

        self::assertSame([], $this->zugriff->costCenters($id));
    }

    // ----------------------------------------------------------- period scope

    public function testAKassenprueferSeesOnlyTheGrantedYear(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen(
            $id,
            [$this->rolle(SystemRole::Kassenpruefer)],
            von: new \DateTimeImmutable('2026-01-01'),
            bis: new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame([2, 3, 4, 5], $this->sichtbar($id, Permission::InboxView));
        self::assertSame([2, 3, 4, 5], $this->sichtbar($id, Permission::BankView));
    }

    public function testRemovingThePeriodRemovesTheRestriction(): void
    {
        $id = $this->konto();
        $rolle = $this->rolle(SystemRole::Steuerberater);
        $this->zuweisung->zuweisen($id, [$rolle], von: new \DateTimeImmutable('2026-01-01'));
        self::assertSame([2, 3, 4, 5, 6], $this->sichtbar($id, Permission::ReportView));

        $this->zuweisung->zuweisen($id, [$rolle]);
        self::assertSame([1, 2, 3, 4, 5, 6], $this->sichtbar($id, Permission::ReportView));
    }

    public function testAPeriodThatEndsBeforeItBeginsIsRefused(): void
    {
        $this->expectException(RoleRuleViolation::class);

        $this->zuweisung->zuweisen(
            $this->konto(),
            [$this->rolle(SystemRole::Kassenpruefer)],
            von: new \DateTimeImmutable('2026-12-31'),
            bis: new \DateTimeImmutable('2026-01-01'),
        );
    }

    // --------------------------------------------------------- external accounts

    public function testAnExternalAccountGetsSixtyDaysAndTwoFactorsByDefault(): void
    {
        $id = $this->konto(mfa: false);
        $jetzt = new \DateTimeImmutable('2026-09-22 10:00:00');

        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Steuerberater)], now: $jetzt);

        $konto = $this->benutzer->findById($id);
        self::assertEquals($jetzt->modify('+60 days'), $konto?->expiresAt);
        self::assertTrue($konto?->mfaRequired);
    }

    public function testAnExternalAccountCanBeExtended(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Kassenpruefer)], ablauf: new \DateTimeImmutable('+10 days'));

        $neu = new \DateTimeImmutable('+90 days');
        $this->zuweisung->verlaengern($id, $neu);

        self::assertSame($neu->format('Y-m-d H:i:s'), $this->benutzer->findById($id)?->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testAnEndDateInThePastIsRefused(): void
    {
        $this->expectException(RoleRuleViolation::class);

        $this->zuweisung->zuweisen($this->konto(), [$this->rolle(SystemRole::Kassenpruefer)], ablauf: new \DateTimeImmutable('-1 day'));
    }

    /** M3-7 (issue #20): an internal account may have an end date, it need not. */
    public function testAnInternalAccountMayHaveAnEndDate(): void
    {
        $id = $this->konto();
        $bis = new \DateTimeImmutable('2030-03-31 00:00:00');
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Finanzen)], ablauf: $bis);
        self::assertEquals($bis, $this->benutzer->findById($id)?->expiresAt);

        $spaeter = new \DateTimeImmutable('2031-03-31 00:00:00');
        $this->zuweisung->verlaengern($id, $spaeter);
        self::assertEquals($spaeter, $this->benutzer->findById($id)?->expiresAt);

        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Finanzen)]);
        self::assertNull($this->benutzer->findById($id)?->expiresAt, 'internal without a date = none');

        $this->expectException(RoleRuleViolation::class);
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Finanzen)], ablauf: new \DateTimeImmutable('-1 day'));
    }

    public function testExternalAndInternalRolesAreNotMixed(): void
    {
        $id = $this->konto();

        try {
            $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Kassenpruefer), $this->rolle(SystemRole::Finanzen)]);
            self::fail('Mixing must be refused.');
        } catch (RoleRuleViolation) {
        }

        self::assertSame([], $this->rollen->forUser($id), 'nothing half-assigned');
    }

    public function testAnInternalAccountHasNoEndDate(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Kassenpruefer)]);
        self::assertNotNull($this->benutzer->findById($id)?->expiresAt);

        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vorstand)]);
        self::assertNull($this->benutzer->findById($id)?->expiresAt);
    }

    /**
     * The end date is enforced on the next request, not at the next login
     * (App\Http\LoginGuard re-reads the account every time).
     */
    public function testAnExpiredExternalAccountIsTurnedAwayMidSession(): void
    {
        $id = $this->konto(mfa: false);
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Kassenpruefer)]);
        $this->benutzer->updateMfaRequired($id, false);
        $this->anmelden($id);

        self::assertSame(200, $this->aufruf()->status, 'still valid');
        self::assertTrue($this->benutzer->findById($id)?->mayLogIn(new \DateTimeImmutable()));

        $this->benutzer->updateExpiry($id, new \DateTimeImmutable('-1 minute'));

        $antwort = $this->aufruf();
        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', $antwort->headers['Location'] ?? '');
        self::assertNull(new Session()->userId(), 'the session is gone');
        self::assertFalse($this->benutzer->findById($id)?->mayLogIn(new \DateTimeImmutable()));
    }

    /** Taking a role away takes effect on the next click. */
    public function testARemovedRoleTakesEffectImmediately(): void
    {
        $id = $this->konto(mfa: false);
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Admin)]);
        $this->anmelden($id);
        self::assertSame(200, $this->aufruf(Zugriff::recht(Permission::AdminSettings))->status);

        // A second admin, so the first one may lose the role.
        $this->zuweisung->zuweisen($this->konto(), [$this->rolle(SystemRole::Admin)]);
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vorstand)]);

        self::assertSame(403, $this->aufruf(Zugriff::recht(Permission::AdminSettings))->status);
    }

    public function testTheLastAdminKeepsTheRole(): void
    {
        $id = $this->konto();
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Admin)]);

        $this->expectException(RoleRuleViolation::class);
        $this->zuweisung->zuweisen($id, [$this->rolle(SystemRole::Vorstand)]);
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * What a repository will do: filter in SQL with the account's scope.
     *
     * @return list<int>
     */
    private function sichtbar(int $userId, Permission $recht): array
    {
        [$bedingung, $parameter] = $this->zugriff->berechtigungen($userId)
            ->zugriffsbereich($recht)
            ->sqlBedingung('p.beleg_datum', 'p.cost_center_id');

        $stmt = $this->pdo()->prepare('SELECT p.id FROM scope_probe p WHERE ' . $bedingung . ' ORDER BY p.id');
        $stmt->execute($parameter);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function konto(bool $mfa = true): int
    {
        return $this->benutzer->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: $mfa);
    }

    private function rolle(SystemRole $rolle): int
    {
        return $this->rollen->findSystem($rolle)?->id ?? throw new \LogicException('seed missing');
    }

    private function anmelden(int $userId): void
    {
        // Started first: session_start() would replace what is set here.
        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    private function aufruf(?Zugriff $zugriff = null): Response
    {
        $pdo = $this->pdo();
        $guard = new LoginGuard(
            new Session(),
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );

        $antwort = $guard->pruefe(
            $zugriff ?? Zugriff::angemeldet(),
            static fn(): Response => Response::html('ok'),
        )(new Request(HttpMethod::Get, '/app'), []);
        assert($antwort instanceof Response);

        return $antwort;
    }
}
