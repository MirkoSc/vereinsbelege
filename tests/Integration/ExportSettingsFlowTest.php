<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\ExportSettingsController;
use App\Config\Paths;
use App\Domain\AuditAction;
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
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Export\ExportEinstellungen;
use App\Service\Export\PfadMuster;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The export path pattern's admin page end to end (issue #75/M12-1,
 * docs/spec/05-auswertung-und-export.md section 2): the real route table,
 * the real guard, the real controller, the real schema.
 */
final class ExportSettingsFlowTest extends DatabaseTestCase
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

    public function testWithoutASettingRowTheDefaultPatternShowsWithItsExample(): void
    {
        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/export'));

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="' . e(PfadMuster::STANDARD) . '"', $seite->body);
        self::assertStringContainsString('Belege_2026/Musterbau GmbH/2026/05. Mai/Musterbau GmbH 17.05.2026 (2).pdf', $seite->body);
        self::assertStringContainsString('Belege_2026/_Ohne Lieferant/2026/03. März/', $seite->body);
    }

    public function testSavingAPatternPersistsAndAudits(): void
    {
        $antwort = $this->post('/admin/export', ['muster' => '  ' . PfadMuster::ARCHIV_KASSE . ' ']);

        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/export', $antwort->headers['Location'] ?? null);
        self::assertSame(PfadMuster::ARCHIV_KASSE, $this->gespeichert());

        $eintrag = new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 1)[0];
        self::assertSame(AuditAction::EinstellungExport->value, $eintrag->action);
        self::assertSame($this->userId, $eintrag->userId);

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/export'));
        self::assertStringContainsString('Belege_2026/2026/03. März/Kasse/_Ohne Lieferant 20.03.2026.pdf', $seite->body);
    }

    public function testAnInvalidPatternKeepsTheFormAndSavesNothing(): void
    {
        $antwort = $this->post('/admin/export', ['muster' => '{jahr}/{name}.pdf']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('Unbekannter Platzhalter', $antwort->body);
        self::assertStringContainsString('value="{jahr}/{name}.pdf"', $antwort->body, 'the input stays for correcting');
        self::assertSame(PfadMuster::STANDARD, $this->gespeichert());
        self::assertSame([], new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 1));
    }

    public function testThePreviewShowsTheInputWithoutSavingIt(): void
    {
        $antwort = $this->post('/admin/export/vorschau', ['muster' => '{richtung}/{jahr}/{monat}/{nr}.pdf']);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Vorschau, noch nicht gespeichert', $antwort->body);
        self::assertStringContainsString('Belege_2026/Einnahmen/2026/04/S-7.pdf', $antwort->body);
        self::assertStringContainsString('Belege_2026/Ausgaben/2026/01/2026-0042.pdf', $antwort->body, 'the "/" in the number became "-"');
        self::assertSame(PfadMuster::STANDARD, $this->gespeichert());
    }

    public function testAnInvalidPreviewShowsTheProblem(): void
    {
        $antwort = $this->post('/admin/export/vorschau', ['muster' => '../{jahr}']);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('nicht erlaubt', $antwort->body);
    }

    public function testABrokenSettingFallsBackToTheDefault(): void
    {
        new SettingRepository($this->pdo())->set(ExportEinstellungen::SETTING_MUSTER, '{kaputt}');

        self::assertSame(PfadMuster::STANDARD, ExportEinstellungen::fromSettings(new SettingRepository($this->pdo()))->muster->muster);
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/export', post: ['muster' => PfadMuster::ARCHIV]));

        self::assertSame(302, $antwort->status);
        self::assertSame(PfadMuster::STANDARD, $this->gespeichert());
    }

    /** export.zip alone (Vorstand) does not configure the pattern - admin.settings does. */
    public function testWithoutAdminSettingsThePageIsForbidden(): void
    {
        $this->alsRolle(SystemRole::Vorstand);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/export'))->status);
        self::assertSame(403, $this->post('/admin/export', ['muster' => PfadMuster::ARCHIV])->status);
        self::assertSame(403, $this->post('/admin/export/vorschau', ['muster' => PfadMuster::ARCHIV])->status);
    }

    // ----------------------------------------------------------- scaffolding

    private function gespeichert(): string
    {
        return ExportEinstellungen::fromSettings(new SettingRepository($this->pdo()))->muster->muster;
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
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
        $exportAdmin = static fn(): ExportSettingsController => new ExportSettingsController(
            $view,
            new Session(),
            new SettingRepository($pdo),
            new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), new ServerCrypto(random_bytes(32))),
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the export pattern page 31st.
        $controller = array_fill(0, 33, $unerreichbar);
        $controller[1] = $guard;
        $controller[30] = $exportAdmin;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')($router, $view, ...$controller);

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
