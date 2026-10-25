<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\CodeIntegritaetController;
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
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Account\PasswordHasher;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Integritaet\CodeIntegritaet;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * /admin/integritaet end to end (issue #79/M13-2, docs/spec/01-sicherheit.md
 * section 8): the real route table, the real guard, the real controller, the
 * real schema - against a small release tree in a temporary directory.
 * Rights per role for every route are also pinned by
 * RoutePermissionMatrixTest.
 */
final class CodeIntegritaetPageFlowTest extends DatabaseTestCase
{
    private string $basis;
    private string $release;
    private string $liste;
    private int $userId;
    private Vault $tresor;
    private ServerCrypto $serverCrypto;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->serverCrypto = new ServerCrypto(random_bytes(32));

        $this->basis = sys_get_temp_dir() . '/vb_integritaetseite_' . bin2hex(random_bytes(6));
        $this->release = $this->basis . '/current';
        $this->liste = $this->basis . '/shared/release_checksums.txt';
        mkdir($this->release . '/app', 0775, true);
        mkdir(dirname($this->liste), 0775, true);
        file_put_contents($this->release . '/VERSION', "2.0.0\n");
        file_put_contents($this->release . '/app/a.php', "<?php // a\n");
        file_put_contents($this->liste, implode("\n", [
            str_repeat('1', 64) . '  vereinsbelege-v2.0.0.zip',
            hash_file('sha256', $this->release . '/VERSION') . '  release/VERSION',
            hash_file('sha256', $this->release . '/app/a.php') . '  release/app/a.php',
        ]) . "\n");

        $this->userId = new UserRepository($this->pdo())->insert(
            'enc',
            random_bytes(32),
            'enc',
            new PasswordHasher()->hash('richtig-geheimes-passwort'),
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
        $laufer = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($laufer as $eintrag) {
            assert($eintrag instanceof \SplFileInfo);
            $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
        }
        rmdir($this->basis);
        parent::tearDown();
    }

    public function testThePageOffersTheCheck(): void
    {
        $antwort = $this->seite();

        self::assertStringContainsString('<h2>Code-Integrität</h2>', $antwort->body);
        self::assertStringContainsString('id="integritaet-pruefen"', $antwort->body);
        self::assertStringContainsString('Jetzt prüfen', $antwort->body);
        self::assertStringContainsString('Noch keine Prüfung durchgeführt.', $antwort->body);
        self::assertStringContainsString('<script src="/js/integritaet.js?v=0.0.0-test" defer></script>', $antwort->body);
        self::assertStringContainsString('href="/admin/integritaet"', $antwort->body, 'in the admin navigation');
    }

    public function testAnUntouchedReleaseIsConfirmedAndAudited(): void
    {
        $this->pruefen();

        $seite = $this->seite();
        self::assertMatchesRegularExpression('#class="hinweis hinweis-ok" id="integritaet-ergebnis"#', $seite->body);
        self::assertStringContainsString('Version 2.0.0', $seite->body);
        self::assertStringContainsString('Neu prüfen', $seite->body);

        $zeilen = $this->audit();
        self::assertCount(1, $zeilen);
        self::assertSame(AuditAction::CodeIntegritaetUnveraendert->value, $zeilen[0]->action);
        self::assertSame($this->userId, $zeilen[0]->userId);
        self::assertSame(
            ['version' => '2.0.0', 'dateien' => 2, 'geaendert' => 0, 'fehlend' => 0, 'zusaetzlich' => 0, 'umgebung' => 0],
            $this->auditLog()->details($zeilen[0], $this->tresor),
        );
    }

    public function testDifferencesAreShownAndAuditedWithoutPaths(): void
    {
        file_put_contents($this->release . '/app/a.php', "<?php // manipuliert\n");
        file_put_contents($this->release . '/app/hintertuer.php', "<?php\n");

        $this->pruefen();

        $seite = $this->seite();
        self::assertMatchesRegularExpression('#class="hinweis hinweis-fehler" id="integritaet-ergebnis"#', $seite->body);
        self::assertMatchesRegularExpression('#<ul id="integritaet-geaendert">\s*<li><code>app/a\.php</code></li>#', $seite->body);
        self::assertMatchesRegularExpression('#<ul id="integritaet-zusaetzlich">\s*<li><code>app/hintertuer\.php</code></li>#', $seite->body);
        self::assertStringNotContainsString('id="integritaet-fehlend"', $seite->body, 'no empty section');

        $zeilen = $this->audit();
        self::assertCount(1, $zeilen);
        self::assertSame(AuditAction::CodeIntegritaetAbweichung->value, $zeilen[0]->action, 'visible without the vault');
        $details = $this->auditLog()->details($zeilen[0], $this->tresor);
        self::assertSame(
            ['version' => '2.0.0', 'dateien' => 2, 'geaendert' => 1, 'fehlend' => 0, 'zusaetzlich' => 1, 'umgebung' => 0],
            $details,
        );
        self::assertStringNotContainsString('hintertuer', (string) json_encode($details), 'counts only');
    }

    /**
     * Without per-file checksums (development, a release before M13-2) the
     * page explains why and offers nothing to start.
     */
    public function testWithoutChecksumsThePageExplainsAndTheStartIsRefused(): void
    {
        unlink($this->liste);

        $seite = $this->seite();
        self::assertStringContainsString('id="integritaet-hindernis"', $seite->body);
        self::assertStringNotContainsString('id="integritaet-pruefen"', $seite->body);

        $start = $this->post('/admin/integritaet');
        self::assertSame(409, $start->status);
        self::assertStringContainsString('keine Prüfsummenliste', $start->body);
        self::assertSame([], $this->audit());
    }

    /**
     * A list of another version (after a rollback) warns, but never blocks:
     * a tampered VERSION must not switch the check off.
     */
    public function testAListOfAnotherVersionWarnsAndTheCheckStillRuns(): void
    {
        file_put_contents($this->release . '/VERSION', "1.9.0\n");

        $seite = $this->seite();
        self::assertStringContainsString('id="integritaet-warnung"', $seite->body);
        self::assertStringContainsString('id="integritaet-pruefen"', $seite->body);

        $this->pruefen();
        self::assertSame(AuditAction::CodeIntegritaetAbweichung->value, $this->audit()[0]->action);
    }

    public function testStartAndStepNeedCsrf(): void
    {
        foreach (['/admin/integritaet', '/admin/integritaet/schritt'] as $pfad) {
            $antwort = $this->dispatch(new Request(HttpMethod::Post, $pfad));
            self::assertInstanceOf(Response::class, $antwort);
            self::assertSame(403, $antwort->status, $pfad);
        }
        self::assertNull($this->pruefung()->stand(), 'nothing started');
    }

    public function testWithoutTheRightEveryRouteAnswers403(): void
    {
        $this->alsRolle(SystemRole::Vorstand);

        foreach ([
            new Request(HttpMethod::Get, '/admin/integritaet'),
            new Request(HttpMethod::Post, '/admin/integritaet', post: ['_csrf' => new Session()->csrfToken()]),
            new Request(HttpMethod::Post, '/admin/integritaet/schritt', post: ['_csrf' => new Session()->csrfToken()]),
        ] as $request) {
            $antwort = $this->dispatch($request);
            self::assertInstanceOf(Response::class, $antwort, $request->path);
            self::assertSame(403, $antwort->status, $request->method->value . ' ' . $request->path);
        }
    }

    public function testAnUnfinishedCheckCanBeContinued(): void
    {
        self::assertSame(200, $this->post('/admin/integritaet')->status);

        $seite = $this->seite();
        self::assertStringContainsString('id="integritaet-unvollstaendig"', $seite->body);
        self::assertMatchesRegularExpression('#<button type="button" class="knopf" id="integritaet-fortsetzen">#', $seite->body);
    }

    private function pruefen(): void
    {
        $start = $this->post('/admin/integritaet');
        self::assertSame(200, $start->status, $start->body);
        $stand = json_decode($start->body, true, flags: JSON_THROW_ON_ERROR);

        $schritte = 0;
        while (!$stand['fertig']) {
            $antwort = $this->post('/admin/integritaet/schritt');
            self::assertSame(200, $antwort->status, $antwort->body);
            $stand = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertLessThan(100, ++$schritte);
        }
        self::assertSame(['phase', 'fertig', 'geprueft', 'gesamt'], array_keys($stand), 'the answers carry no paths');
    }

    private function seite(): Response
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Get, '/admin/integritaet'));
        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(200, $antwort->status);

        return $antwort;
    }

    private function post(string $pfad): Response
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, $pfad, post: ['_csrf' => new Session()->csrfToken()]));
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return list<AuditEntry> newest first
     */
    private function audit(): array
    {
        return new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 20);
    }

    private function alsRolle(SystemRole $rolle): void
    {
        $rollen = new RoleRepository($this->pdo());
        $id = $rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $rollen->assignToUser($this->userId, [$id]);
    }

    private function pruefung(): CodeIntegritaet
    {
        return new CodeIntegritaet($this->release, $this->liste, $this->basis . '/shared/var');
    }

    private function auditLog(): AuditLog
    {
        $pdo = $this->pdo();

        return new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $this->serverCrypto);
    }

    private function dispatch(Request $request): ResponseInterface
    {
        return $this->kernel()->handle($request);
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

        // The 38 required controller closures of app/src/routes.php, the
        // guard second; the integrity page is the optional last one.
        $controller = array_fill(0, 38, $unerreichbar);
        $controller[1] = $guard;

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            ...$controller,
            integritaet: fn(): CodeIntegritaetController => new CodeIntegritaetController(
                $view,
                new Session(),
                $this->pruefung(),
                $this->auditLog(),
            ),
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: true,
        );
    }
}
