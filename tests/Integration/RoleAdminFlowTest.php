<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\RoleController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\Permission;
use App\Domain\PermissionScope;
use App\Domain\Role;
use App\Domain\SystemRole;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\RoleService;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The role pages end to end (M3-6, issue #19): the real route table, the
 * real guard, the real controller, the real schema.
 */
final class RoleAdminFlowTest extends DatabaseTestCase
{
    private RoleRepository $rollen;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash', mfaRequired: false);
        $this->alsRolle(SystemRole::Admin);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheListShowsTheShippedRoles(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen'));

        self::assertSame(200, $antwort->status);
        foreach (SystemRole::cases() as $rolle) {
            self::assertStringContainsString(e($rolle->bezeichnung()), $antwort->body);
        }
        self::assertStringContainsString('href="/admin/rollen"', $antwort->body, 'the navigation entry is live');
    }

    public function testCreateChangeAndDeleteARole(): void
    {
        $antwort = $this->post('/admin/rollen', [
            'name' => 'Jugendkasse',
            'recht' => ['inbox.view' => '1', 'document.submit_internal' => '1', 'bank.view' => ''],
            'reichweite' => ['inbox.view' => 'kostenstelle'],
        ]);
        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/rollen', $antwort->headers['Location'] ?? null);

        $rolle = $this->eigene('Jugendkasse');
        self::assertSame(
            [
                Permission::InboxView->value => PermissionScope::Kostenstelle,
                Permission::DocumentSubmitInternal->value => PermissionScope::Alle,
            ],
            $rolle->rechte(),
        );

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen/' . $rolle->id));
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="Jugendkasse"', $seite->body);
        self::assertStringContainsString('Rolle löschen', $seite->body);

        $this->post('/admin/rollen/' . $rolle->id, ['name' => 'Jugend', 'recht' => ['report.view' => '1']]);
        self::assertSame([Permission::ReportView->value => PermissionScope::Alle], $this->eigene('Jugend')->rechte());

        $this->post('/admin/rollen/' . $rolle->id . '/loeschen', []);
        self::assertNull($this->rollen->find($rolle->id));

        // Each change is audited, with the role as its object (M3-8).
        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'role', entityId: $rolle->id), null, 10);
        self::assertSame(
            [AuditAction::RolleGeloescht->value, AuditAction::RolleGeaendert->value, AuditAction::RolleAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->userId, $zeilen[0]->userId);
    }

    public function testAValidationErrorKeepsTheForm(): void
    {
        $antwort = $this->post('/admin/rollen', ['name' => 'Revision', 'extern' => '1', 'recht' => ['document.edit' => '1']]);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('nur lesen', $antwort->body);
        self::assertStringContainsString('value="Revision"', $antwort->body);
        self::assertCount(6, $this->rollen->all(), 'nothing created');
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/rollen', post: ['name' => 'Ohne Token']));

        self::assertSame(302, $antwort->status);
        self::assertCount(6, $this->rollen->all());
    }

    public function testTheAdminRoleIsShownReadOnly(): void
    {
        $admin = $this->rollen->findSystem(SystemRole::Admin);
        assert($admin !== null);

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen/' . $admin->id));

        self::assertStringContainsString('immer alle Rechte', $seite->body);
        self::assertStringNotContainsString('>Speichern<', $seite->body);
        self::assertStringNotContainsString('Rolle löschen', $seite->body);
    }

    public function testAnUnknownRoleIs404(): void
    {
        self::assertSame(404, $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen/999999'))->status);
    }

    /** Vorstand holds no admin.* right: neither the page nor a write. */
    public function testWithoutAdminUsersThePagesAreForbidden(): void
    {
        $this->alsRolle(SystemRole::Vorstand);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen'))->status);
        self::assertSame(403, $this->post('/admin/rollen', ['name' => 'Heimlich'])->status);
        self::assertCount(6, $this->rollen->all());
    }

    /** An admin.* right other than admin.users opens /admin but not the roles. */
    public function testAnotherAdminRightIsNotEnough(): void
    {
        $technik = $this->rollen->create('Technik', false, [Permission::AdminSystem->value => PermissionScope::Alle]);
        $admin = $this->rollen->findSystem(SystemRole::Admin);
        assert($admin !== null);
        // Keep an admin around, then make this account a Technik one.
        $anderer = new UserRepository($this->pdo())->insert('enc', random_bytes(32), 'enc', 'hash');
        $this->rollen->assignToUser($anderer, [$admin->id]);
        $this->rollen->assignToUser($this->userId, [$technik]);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/rollen'))->status);
        $start = $this->dispatch(new Request(HttpMethod::Get, '/admin'));
        self::assertSame('/admin/update', $start->headers['Location'] ?? null);
    }

    // ----------------------------------------------------------- scaffolding

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    private function eigene(string $name): Role
    {
        foreach ($this->rollen->all() as $rolle) {
            if ($rolle->name === $name) {
                return $rolle;
            }
        }

        self::fail('Role not found: ' . $name);
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder): Response
    {
        return $this->dispatch(new Request(HttpMethod::Post, $pfad, post: [...$felder, '_csrf' => new Session()->csrfToken()]));
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

        $guard = static fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            static function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null ? null : new SessionUser($user, 'Test', new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $rollen = static fn(): RoleController => new RoleController(
            $view,
            new Session(),
            new RoleRepository($pdo),
            new RoleService(new RoleRepository($pdo)),
            new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), new ServerCrypto(random_bytes(32))),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $unerreichbar,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $rollen,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
