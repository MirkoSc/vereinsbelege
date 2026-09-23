<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\CostCenterController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\CostCenter;
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
use App\Repository\CostCenterRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\MasterData\CostCenterService;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The cost-center pages end to end (M4-1, issue #23): the real route
 * table, the real guard, the real controller, the real schema.
 */
final class CostCenterAdminFlowTest extends DatabaseTestCase
{
    private CostCenterRepository $kostenstellen;

    private RoleRepository $rollen;

    private UserAccessRepository $zugriff;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->kostenstellen = new CostCenterRepository($this->pdo());
        $this->rollen = new RoleRepository($this->pdo());
        $this->zugriff = new UserAccessRepository($this->pdo());
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

    public function testTheListIsEmptyAtFirst(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/kostenstellen'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Neue Kostenstelle', $antwort->body);
    }

    public function testCreateChangeReorderAndDeleteACostCenter(): void
    {
        $a = $this->post('/admin/kostenstellen', ['name' => 'Herren']);
        self::assertSame(302, $a->status);
        self::assertSame('/admin/kostenstellen', $a->headers['Location'] ?? null);
        $b = $this->post('/admin/kostenstellen', ['name' => 'Damen']);
        self::assertSame(302, $b->status);

        $herren = $this->eigene('Herren');
        $damen = $this->eigene('Damen');
        self::assertTrue($herren->sort < $damen->sort, 'created in order');

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/kostenstellen/' . $herren->id));
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="Herren"', $seite->body);
        self::assertStringContainsString('Kostenstelle löschen', $seite->body);

        // Reorder: move "Damen" up, ahead of "Herren".
        $this->post('/admin/kostenstellen/' . $damen->id . '/nach-oben', []);
        $geordnet = $this->kostenstellen->all();
        self::assertSame(['Damen', 'Herren'], array_map(static fn(CostCenter $c): string => $c->name, $geordnet));

        // A no-op at the top of the list.
        $this->post('/admin/kostenstellen/' . $damen->id . '/nach-oben', []);
        self::assertSame(['Damen', 'Herren'], array_map(static fn(CostCenter $c): string => $c->name, $this->kostenstellen->all()));

        // Rename and deactivate.
        $this->post('/admin/kostenstellen/' . $herren->id, ['name' => 'Herren I', 'active' => '']);
        $geaendert = $this->kostenstellen->find($herren->id);
        self::assertNotNull($geaendert);
        self::assertSame('Herren I', $geaendert->name);
        self::assertFalse($geaendert->active);
        self::assertArrayNotHasKey($herren->id, $this->kostenstellen->active(), 'inactive is not offered for new assignments');

        $this->post('/admin/kostenstellen/' . $herren->id . '/loeschen', []);
        self::assertNull($this->kostenstellen->find($herren->id));

        // Each change is audited, with the cost center as its object (M3-8).
        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'cost_center', entityId: $herren->id), null, 10);
        self::assertSame(
            [AuditAction::KostenstelleGeloescht->value, AuditAction::KostenstelleGeaendert->value, AuditAction::KostenstelleAngelegt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->userId, $zeilen[0]->userId);
    }

    public function testAValidationErrorKeepsTheForm(): void
    {
        $this->post('/admin/kostenstellen', ['name' => 'Vereinsheim']);

        $antwort = $this->post('/admin/kostenstellen', ['name' => 'Vereinsheim']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('gibt es schon', $antwort->body);
        self::assertCount(1, $this->kostenstellen->all(), 'nothing created twice');
    }

    public function testAnEmptyNameIsRejected(): void
    {
        $antwort = $this->post('/admin/kostenstellen', ['name' => '   ']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Namen angeben', $antwort->body);
        self::assertCount(0, $this->kostenstellen->all());
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/kostenstellen', post: ['name' => 'Ohne Token']));

        self::assertSame(302, $antwort->status);
        self::assertCount(0, $this->kostenstellen->all());
    }

    public function testAnAssignedCostCenterIsNotDeleted(): void
    {
        $id = $this->kostenstellen->create('Jugend');
        $this->zugriff->setCostCenters($this->userId, [$id]);

        $antwort = $this->post('/admin/kostenstellen/' . $id . '/loeschen', []);

        self::assertSame(302, $antwort->status);
        self::assertNotNull($this->kostenstellen->find($id), 'still there');
        self::assertSame([$id], $this->zugriff->costCenters($this->userId), 'assignment untouched');

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/kostenstellen/' . $id));
        self::assertStringContainsString('noch Benutzern zugewiesen', $seite->body);
    }

    /**
     * The rule enforced in PHP (App\Service\MasterData\CostCenterService)
     * is backed by the schema (migrations/012_cost_center_restrict.sql):
     * a raw DELETE, bypassing the service, still fails.
     */
    public function testTheForeignKeyAloneRefusesTheDelete(): void
    {
        $id = $this->kostenstellen->create('Jugend');
        $this->zugriff->setCostCenters($this->userId, [$id]);

        $this->expectException(\PDOException::class);
        $this->pdo()->prepare('DELETE FROM cost_center WHERE id = ?')->execute([$id]);
    }

    public function testAnUnknownCostCenterIs404(): void
    {
        self::assertSame(404, $this->dispatch(new Request(HttpMethod::Get, '/admin/kostenstellen/999999'))->status);
    }

    /** Vorstand holds admin.settings for nothing (only Admin does). */
    public function testWithoutAdminSettingsThePagesAreForbidden(): void
    {
        $this->alsRolle(SystemRole::Vorstand);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/kostenstellen'))->status);
        self::assertSame(403, $this->post('/admin/kostenstellen', ['name' => 'Heimlich'])->status);
        self::assertCount(0, $this->kostenstellen->all());
    }

    // ----------------------------------------------------------- scaffolding

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    private function eigene(string $name): CostCenter
    {
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            if ($kostenstelle->name === $name) {
                return $kostenstelle;
            }
        }

        self::fail('Cost center not found: ' . $name);
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
        $kostenstellen = static fn(): CostCenterController => new CostCenterController(
            $view,
            new Session(),
            new CostCenterRepository($pdo),
            new CostCenterService(new CostCenterRepository($pdo)),
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
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $kostenstellen,
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
