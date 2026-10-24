<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\BackupController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\SystemRole;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Http\StreamResponse;
use App\Repository\AuditLogRepository;
use App\Repository\RateLimitRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\PasswordHasher;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * /admin/backups end to end (issue #112): the real route table, the real
 * guard, the real controller, the real schema. Rights per role for every
 * route are also pinned by RoutePermissionMatrixTest.
 */
final class BackupPageFlowTest extends DatabaseTestCase
{
    private const string PASSWORT = 'richtig-geheimes-passwort';

    private string $backupDir;
    private string $configFile;
    private string $blobDir;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->backupDir = sys_get_temp_dir() . '/vb_backupseite_' . uniqid('', true);
        mkdir($this->backupDir, 0775, true);
        $this->configFile = sys_get_temp_dir() . '/vb_backupseite_config_' . uniqid('', true) . '.php';
        file_put_contents($this->configFile, "<?php return ['server_key' => 'geheim'];\n");
        $this->blobDir = sys_get_temp_dir() . '/vb_backupseite_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $this->userId = new UserRepository($this->pdo())->insert(
            'enc',
            random_bytes(32),
            'enc',
            new PasswordHasher()->hash(self::PASSWORT),
            mfaRequired: false,
        );
        $this->alsRolle(SystemRole::Admin);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (glob($this->backupDir . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        @rmdir($this->backupDir);
        @rmdir($this->blobDir);
        @unlink($this->configFile);
        parent::tearDown();
    }

    public function testThePageOffersCreationWithTheConfigChoiceAndTheWarning(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/backups'));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Backup jetzt erstellen', $antwort->body);
        self::assertMatchesRegularExpression('#<input type="checkbox" name="mit_config" value="1"[^>]*>#', $antwort->body);
        self::assertStringNotContainsString('name="mit_config" value="1" checked', $antwort->body, 'off by default');
        self::assertStringContainsString('enthält den Server-Schlüssel', mb_strtolower($antwort->body));
        self::assertStringContainsString('id="backup-warnung-ohne-config"', $antwort->body);
        self::assertStringContainsString('/js/backups.js', $antwort->body);
        self::assertStringContainsString('Noch keine Backups vorhanden.', $antwort->body);
    }

    public function testCreateListAndDownloadABackupWithoutConfig(): void
    {
        $name = $this->erstellen(false);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        self::assertNotFalse($zip->locateName('dump.sql'));
        self::assertFalse($zip->locateName('config.php'), 'config.php only on request');
        $zip->close();

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/backups'));
        self::assertInstanceOf(Response::class, $seite);
        self::assertStringContainsString('Backup ' . $name . ' erstellt.', $seite->body, 'flash after the last step');
        self::assertStringContainsString('href="/admin/backups/' . $name . '"', $seite->body);
        self::assertStringContainsString('ohne config.php', $seite->body);

        $download = $this->dispatch(new Request(HttpMethod::Get, '/admin/backups/' . $name));
        self::assertInstanceOf(StreamResponse::class, $download);
        $inhalt = '';
        foreach ($download->chunks as $stueck) {
            $inhalt .= $stueck;
        }
        self::assertSame((string) file_get_contents($this->backupDir . '/' . $name), $inhalt, 'the whole file, streamed');
        self::assertSame((string) filesize($this->backupDir . '/' . $name), $download->headers['Content-Length']);
        self::assertSame('attachment; filename="' . $name . '"', $download->headers['Content-Disposition']);
        self::assertSame('no-store', $download->headers['Cache-Control']);

        $zeilen = $this->audit();
        self::assertSame(
            [AuditAction::BackupHeruntergeladen->value, AuditAction::BackupErstellt->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->userId, $zeilen[0]->userId);
    }

    public function testTheConfigCheckboxPutsConfigIntoTheZip(): void
    {
        $name = $this->erstellen(true);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        self::assertSame((string) file_get_contents($this->configFile), $zip->getFromName('config.php'));
        $zip->close();
    }

    /**
     * A backup with config.php carries the server key: no plain GET, and the
     * POST needs CSRF and the account's password.
     */
    public function testABackupWithConfigNeedsThePassword(): void
    {
        $name = $this->erstellen(true);

        $get = $this->dispatch(new Request(HttpMethod::Get, '/admin/backups/' . $name));
        self::assertNotInstanceOf(StreamResponse::class, $get);
        self::assertInstanceOf(Response::class, $get);
        self::assertSame(403, $get->status);

        $ohneCsrf = $this->dispatch(new Request(HttpMethod::Post, '/admin/backups/' . $name, post: ['passwort' => self::PASSWORT]));
        self::assertNotInstanceOf(StreamResponse::class, $ohneCsrf);

        $falsch = $this->post('/admin/backups/' . $name, ['passwort' => 'falsch']);
        self::assertNotInstanceOf(StreamResponse::class, $falsch);
        self::assertInstanceOf(Response::class, $falsch);
        self::assertSame('/admin/backups', $falsch->headers['Location'] ?? null);

        $seite = $this->dispatch(new Request(HttpMethod::Get, '/admin/backups'));
        self::assertInstanceOf(Response::class, $seite);
        self::assertStringContainsString('Das Passwort ist falsch', $seite->body);
        self::assertStringContainsString('mit config.php', $seite->body);
        self::assertStringContainsString('autocomplete="current-password"', $seite->body);

        $richtig = $this->post('/admin/backups/' . $name, ['passwort' => self::PASSWORT]);
        self::assertInstanceOf(StreamResponse::class, $richtig);

        $heruntergeladen = $this->audit(AuditAction::BackupHeruntergeladen);
        self::assertCount(1, $heruntergeladen, 'only the confirmed download is logged');
    }

    public function testTooManyWrongPasswordsBlockTheDownload(): void
    {
        $name = $this->erstellen(true);
        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_ACCOUNT; $i++) {
            $this->post('/admin/backups/' . $name, ['passwort' => 'falsch']);
        }

        $antwort = $this->post('/admin/backups/' . $name, ['passwort' => self::PASSWORT]);

        self::assertNotInstanceOf(StreamResponse::class, $antwort);
    }

    public function testTheDownloadServesOnlyFilesFromTheBackupDirectory(): void
    {
        // Lies next to the backups but is no backup.
        file_put_contents($this->backupDir . '/backup_notiz.zip', 'x');

        foreach ([
            '/admin/backups/backup_20260101_120000.zip',
            '/admin/backups/backup_notiz.zip',
            '/admin/backups/..%2Fconfig.php',
            '/admin/backups/../../shared/config.php',
            '/admin/backups/erstellung.json',
        ] as $pfad) {
            $antwort = $this->dispatch(new Request(HttpMethod::Get, $pfad));
            self::assertNotInstanceOf(StreamResponse::class, $antwort, $pfad);
            self::assertInstanceOf(Response::class, $antwort);
            self::assertSame(404, $antwort->status, $pfad);
        }

        // Also when the controller is reached with a forged name.
        $controller = $this->controller();
        $antwort = $controller->download(new Request(HttpMethod::Get, '/admin/backups/x'), ['name' => '../' . basename($this->configFile)]);
        self::assertNotInstanceOf(StreamResponse::class, $antwort);
    }

    public function testWithoutTheRightEveryRouteAnswers403(): void
    {
        $name = $this->erstellen(false);
        $rollen = new RoleRepository($this->pdo());
        $vorstand = $rollen->findSystem(SystemRole::Vorstand)?->id;
        assert($vorstand !== null);
        $rollen->assignToUser($this->userId, [$vorstand]);

        foreach ([
            new Request(HttpMethod::Get, '/admin/backups'),
            new Request(HttpMethod::Get, '/admin/backups/' . $name),
            new Request(HttpMethod::Post, '/admin/backups', post: ['_csrf' => new Session()->csrfToken()]),
            new Request(HttpMethod::Post, '/admin/backups/schritt', post: ['_csrf' => new Session()->csrfToken()]),
            new Request(HttpMethod::Post, '/admin/backups/' . $name, post: ['_csrf' => new Session()->csrfToken(), 'passwort' => self::PASSWORT]),
        ] as $request) {
            $antwort = $this->dispatch($request);
            self::assertInstanceOf(Response::class, $antwort, $request->path);
            self::assertSame(403, $antwort->status, $request->method->value . ' ' . $request->path);
        }
    }

    public function testCreatingNeedsCsrf(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/backups', post: ['mit_config' => '1']));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(403, $antwort->status);
        self::assertNull($this->erstellung()->stand(), 'nothing started');

        $schritt = $this->dispatch(new Request(HttpMethod::Post, '/admin/backups/schritt'));
        self::assertInstanceOf(Response::class, $schritt);
        self::assertSame(403, $schritt->status);
    }

    public function testASecondStartWhileOneRunsAnswers409(): void
    {
        self::assertSame(200, $this->json('/admin/backups', [])->status);

        $zweiter = $this->json('/admin/backups', []);

        self::assertSame(409, $zweiter->status);
    }

    private function erstellen(bool $mitConfig): string
    {
        $start = $this->json('/admin/backups', $mitConfig ? ['mit_config' => '1'] : []);
        self::assertSame(200, $start->status, $start->body);
        $stand = json_decode($start->body, true, flags: JSON_THROW_ON_ERROR);

        $schritte = 0;
        while (!$stand['fertig']) {
            $antwort = $this->json('/admin/backups/schritt', []);
            self::assertSame(200, $antwort->status, $antwort->body);
            $stand = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertLessThan(100, ++$schritte);
        }
        self::assertArrayNotHasKey('id', $stand, 'the answer carries no internals');

        return (string) $stand['name'];
    }

    /**
     * @param array<string, string> $felder
     */
    private function json(string $pfad, array $felder): Response
    {
        $antwort = $this->post($pfad, $felder);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function audit(?AuditAction $aktion = null): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(aktion: $aktion), null, 20);
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @param array<string, string> $felder
     */
    private function post(string $pfad, array $felder): ResponseInterface
    {
        return $this->dispatch(new Request(HttpMethod::Post, $pfad, post: [...$felder, '_csrf' => new Session()->csrfToken()]));
    }

    private function dispatch(Request $request): ResponseInterface
    {
        return $this->kernel()->handle($request);
    }

    private function backups(): BackupService
    {
        return new BackupService($this->pdo(), $this->backupDir, $this->configFile, '9.9.9-test', $this->blobDir);
    }

    private function erstellung(): BackupErstellung
    {
        return new BackupErstellung($this->backups(), $this->pdo(), $this->backupDir, $this->configFile);
    }

    private function controller(): BackupController
    {
        $pdo = $this->pdo();
        $view = new View(new Paths(dirname(__DIR__, 2))->viewsDir(), '0.0.0-test');

        return new BackupController(
            $view,
            new Session(),
            $this->backups(),
            $this->erstellung(),
            new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), new ServerCrypto(random_bytes(32))),
            new UserRepository($pdo),
            new PasswordHasher(),
            new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
        );
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
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        // Every controller closure of app/src/routes.php in order: the
        // guard second, the optional Nextcloud page 39th, the backups page
        // 40th.
        $controller = array_fill(0, 40, $unerreichbar);
        $controller[1] = $guard;
        $controller[39] = fn(): BackupController => $this->controller();

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
