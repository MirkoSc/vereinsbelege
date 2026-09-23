<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\SubmissionSettingsController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
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
use App\Service\Migration\Migrator;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The public submission's admin page end to end (issue #25/M4-3, docs/spec/
 * 01-sicherheit.md section 5): the real route table, the real guard, the
 * real controller, the real schema.
 */
final class SubmissionSettingsFlowTest extends DatabaseTestCase
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

    public function testTheDefaultsShowWithoutAnySettingRow(): void
    {
        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/einreichung'));

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('value="10"', $seite->body, 'limit per IP');
        self::assertStringContainsString('Aktiv', $seite->body);
    }

    public function testSavingTheLimitsPersistsAndAudits(): void
    {
        $antwort = $this->post('/admin/einreichung', [
            'limit_ip_stunde' => '5',
            'limit_gesamt_stunde' => '30',
            'max_seiten' => '8',
            'max_datei_mb' => '4',
            'max_einreichung_mb' => '20',
        ]);
        self::assertSame(302, $antwort->status);
        self::assertSame('/admin/einreichung', $antwort->headers['Location'] ?? null);

        $gespeichert = EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()));
        self::assertSame(5, $gespeichert->limitProIpStunde);
        self::assertSame(30, $gespeichert->limitGesamtStunde);
        self::assertSame(8, $gespeichert->maxSeiten);
        self::assertSame(4, $gespeichert->maxDateiMb);
        self::assertSame(20, $gespeichert->maxEinreichungMb);

        $eintrag = new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 1)[0];
        self::assertSame(AuditAction::EinstellungEinreichung->value, $eintrag->action);
        self::assertSame($this->userId, $eintrag->userId);
    }

    public function testAnInvalidLimitKeepsTheForm(): void
    {
        $antwort = $this->post('/admin/einreichung', [
            'limit_ip_stunde' => '0',
            'limit_gesamt_stunde' => '30',
            'max_seiten' => '8',
            'max_datei_mb' => '4',
            'max_einreichung_mb' => '20',
        ]);

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('positive Zahl', $antwort->body);
        self::assertSame(60, EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()))->limitGesamtStunde, 'nothing was saved');
    }

    public function testPausingAndResumingTogglesTheFlagAndAudits(): void
    {
        $pausiert = $this->post('/admin/einreichung/pausieren', []);
        self::assertSame(302, $pausiert->status);
        self::assertTrue(EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()))->pausiert);

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/einreichung'));
        self::assertStringContainsString('Pausiert', $seite->body);

        $fortgesetzt = $this->post('/admin/einreichung/fortsetzen', []);
        self::assertSame(302, $fortgesetzt->status);
        self::assertFalse(EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()))->pausiert);

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 10);
        self::assertSame(
            [AuditAction::EinstellungEinreichung->value, AuditAction::EinstellungEinreichung->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/einreichung/pausieren', post: []));

        self::assertSame(302, $antwort->status);
        self::assertFalse(EinreichungsEinstellungen::fromSettings(new SettingRepository($this->pdo()))->pausiert);
    }

    /** Vorstand holds admin.settings for nothing (only Admin does). */
    public function testWithoutAdminSettingsThePageIsForbidden(): void
    {
        $this->alsRolle(SystemRole::Vorstand);

        self::assertSame(403, $this->dispatch(new Request(HttpMethod::Get, '/admin/einreichung'))->status);
        self::assertSame(403, $this->post('/admin/einreichung/pausieren', [])->status);
    }

    // ----------------------------------------------------------- scaffolding

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
        $einreichungAdmin = static fn(): SubmissionSettingsController => new SubmissionSettingsController(
            $view,
            new Session(),
            new SettingRepository($pdo),
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
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $einreichungAdmin,
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
