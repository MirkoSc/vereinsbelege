<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AuditController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\SystemRole;
use App\Http\Cookie;
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
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * The audit log page end to end (M3-8, issue #21, docs/spec/
 * 01-sicherheit.md section 6): the real route table, the real guard, the
 * real controller, the real schema.
 */
final class AuditPageTest extends DatabaseTestCase
{
    private const string IP = '198.51.100.7';

    private ServerCrypto $crypto;
    private RoleRepository $rollen;
    private Vault $tresor;
    private AuditLog $audit;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->rollen = new RoleRepository($this->pdo());
        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('pruefer@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Petra Prüfer'),
            'hash',
            mfaRequired: false,
        );
        $this->alsRolle(SystemRole::Kassenpruefer);

        new Session()->start();
        $jetzt = time();
        $_SESSION = ['user_id' => $this->userId, 'login_at' => $jetzt, 'last_seen_at' => $jetzt, 'session_epoch' => 0];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * `audit.view` in the shipped roles: Admin, Vorstand, Kassenprüfer
     * (01 section 4) - and the page is in /app so the latter two reach it.
     *
     * @return iterable<string, array{SystemRole, int}>
     */
    public static function rollen(): iterable
    {
        yield 'Admin' => [SystemRole::Admin, 200];
        yield 'Vorstand' => [SystemRole::Vorstand, 200];
        yield 'Kassenprüfer' => [SystemRole::Kassenpruefer, 200];
        yield 'Finanzen' => [SystemRole::Finanzen, 403];
        yield 'Steuerberater' => [SystemRole::Steuerberater, 403];
        yield 'Vereinsverantwortlicher' => [SystemRole::Vereinsverantwortlicher, 403];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rollen')]
    public function testOnlyAuditViewOpensThePage(SystemRole $rolle, int $status): void
    {
        $this->alsRolle($rolle);

        $antwort = $this->get('/app/audit');

        self::assertSame($status, $antwort->status);
        self::assertSame($status, $this->pruefen(0)->status);
        if ($status === 200) {
            self::assertStringContainsString('href="/app/audit"', $antwort->body, 'the navigation entry is live');
        }
    }

    public function testTheListShowsPlaintextColumnsAndHidesDetailsWithoutVault(): void
    {
        $this->audit->record(AuditAction::BenutzerGesperrt, $this->userId, self::IP, $this->userId, ['grund' => 'Testgrund']);

        $antwort = $this->get('/app/audit');

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Benutzer gesperrt', $antwort->body);
        self::assertStringContainsString('Petra Prüfer', $antwort->body);
        self::assertStringContainsString('verschlüsselt', $antwort->body);
        self::assertStringNotContainsString('Testgrund', $antwort->body);
        self::assertStringNotContainsString(self::IP, $antwort->body);
        self::assertStringContainsString('/js/audit.js', $antwort->body);
    }

    public function testWithTheUnlockedVaultTheDetailsShow(): void
    {
        $this->audit->record(AuditAction::BenutzerGesperrt, $this->userId, self::IP, $this->userId, ['grund' => 'Testgrund']);
        $cookie = new SessionVault()->store($this->tresor);

        $antwort = $this->get('/app/audit', cookies: [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => $cookie]);

        self::assertStringContainsString('grund: Testgrund', $antwort->body);
    }

    public function testTheFilterNarrowsTheList(): void
    {
        $this->audit->record(AuditAction::RolleAngelegt, $this->userId, self::IP, 4, ['name' => 'X']);
        $this->audit->record(AuditAction::Logout, $this->userId, self::IP, $this->userId);

        $antwort = $this->get('/app/audit', ['aktion' => AuditAction::Logout->value]);

        self::assertStringContainsString('<td>Abmeldung</td>', $antwort->body);
        self::assertStringNotContainsString('<td>Rolle angelegt</td>', $antwort->body);
        self::assertStringContainsString('Filter zurücksetzen', $antwort->body);
    }

    public function testOlderEntriesArePagedWithTheFilterKept(): void
    {
        for ($i = 0; $i < AuditController::SEITE + 3; ++$i) {
            $this->audit->record(AuditAction::LoginErfolg, $this->userId, self::IP, $this->userId);
        }

        $erste = $this->get('/app/audit', ['aktion' => AuditAction::LoginErfolg->value]);
        self::assertSame(1, preg_match('#href="(/app/audit\?[^"]+)">Ältere Einträge#', $erste->body, $treffer));
        $link = html_entity_decode($treffer[1]);
        self::assertStringContainsString('aktion=login.erfolg', $link);
        self::assertStringContainsString('vor=4', $link, 'the page ended at id 4');

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $zweite = $this->get('/app/audit', $query);

        self::assertSame(3, substr_count($zweite->body, '<td>Anmeldung</td>'));
        self::assertStringNotContainsString('Ältere Einträge', $zweite->body);
        self::assertStringContainsString('Neueste Einträge', $zweite->body);
    }

    public function testTheCheckReportsAnIntactChainWithItsControlValue(): void
    {
        $kopf = null;
        for ($i = 0; $i < 3; ++$i) {
            $kopf = $this->audit->record(AuditAction::Logout, $this->userId, self::IP, $this->userId);
        }

        $antwort = $this->pruefen(0);
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $antwort->status);
        self::assertSame(3, $daten['geprueft']);
        self::assertSame(3, $daten['gesamt']);
        self::assertTrue($daten['fertig']);
        self::assertNull($daten['bruch']);
        self::assertSame(bin2hex((string) $kopf?->hash), $daten['kopf']);
    }

    public function testTheCheckNamesTheBrokenRow(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->audit->record(AuditAction::Logout, $this->userId, self::IP, $this->userId);
        }
        $this->pdo()->exec('UPDATE audit_log SET user_id = NULL WHERE id = 2');

        $daten = json_decode($this->pruefen(0)->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($daten['fertig']);
        self::assertSame(2, $daten['bruch']['id']);
        self::assertSame('Der Inhalt des Eintrags wurde verändert.', $daten['bruch']['meldung']);
        self::assertNull($daten['kopf'], 'no control value for a broken chain');
    }

    public function testTheCheckOfAnEmptyLogIsDoneWithoutAControlValue(): void
    {
        $daten = json_decode($this->pruefen(0)->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($daten['fertig']);
        self::assertSame(0, $daten['geprueft']);
        self::assertNull($daten['kopf']);
    }

    public function testTheCheckNeedsTheCsrfToken(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/app/audit/pruefen', post: ['nach_id' => '0']));

        self::assertSame(403, $antwort->status);
        self::assertStringContainsString('fehler', $antwort->body);
    }

    // ----------------------------------------------------------- scaffolding

    private function alsRolle(SystemRole $rolle): void
    {
        $id = $this->rollen->findSystem($rolle)?->id;
        assert($id !== null);
        $this->rollen->assignToUser($this->userId, [$id]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $cookies
     */
    private function get(string $pfad, array $query = [], array $cookies = []): Response
    {
        return $this->dispatch(new Request(HttpMethod::Get, $pfad, query: $query, cookies: $cookies));
    }

    private function pruefen(int $nachId): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/audit/pruefen',
            post: ['nach_id' => (string) $nachId, '_csrf' => new Session()->csrfToken()],
        ));
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

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            static fn(): SessionTimeouts => new SessionTimeouts(),
            function (int $id) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($id);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($id));
            },
        );
        $auditSeite = fn(): AuditController => new AuditController(
            $view,
            new Session(),
            new SessionVault(),
            new AuditLogRepository($pdo),
            $this->audit,
            new UserRepository($pdo),
            $this->crypto,
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
            $auditSeite,
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
