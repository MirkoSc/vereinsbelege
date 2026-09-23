<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\VaultGrantController;
use App\Admin\VaultRecoveryController;
use App\App\AuthController;
use App\App\LoginCompleter;
use App\App\PasswordController;
use App\App\PasswordToolbox;
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
use App\Installer\FirstAdminSetup;
use App\Repository\AuditLogRepository;
use App\Repository\MailQueueRepository;
use App\Repository\MfaBackupCodeRepository;
use App\Repository\MfaEmailCodeRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\RateLimitRepository;
use App\Repository\RoleRepository;
use App\Repository\SettingRepository;
use App\Repository\TrustedDeviceRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\AccessAssignment;
use App\Service\Account\Invitation;
use App\Service\Account\LoginService;
use App\Service\Account\MfaService;
use App\Service\Account\PasswordHasher;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswordReset;
use App\Service\Account\PendingLogin;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Account\UserAdministration;
use App\Service\Account\VaultRecovery;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Mail\FreigabeBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;
use App\View\View;

/**
 * The paper recovery key end to end (issue #22/M3-9, docs/spec/
 * 01-sicherheit.md section 2 "Letzter Admin hat Passwort vergessen"), through
 * the real route table and controllers - the technique of
 * UserManagementFlowTest: $_SESSION is the session file, the vault cookie of
 * a login response travels into later requests.
 *
 * The Pflicht-Test of spec 01 this covers: "Wiederherstellungsschlüssel-Flow".
 */
final class VaultRecoveryFlowTest extends DatabaseTestCase
{
    private const string ADMIN = 'vorsitz@example.org';

    private const string ADMIN_PW = 'korrekt-pferd-batterie-heftklammer';

    private const string NEUES_PASSWORT = 'ein-ganz-anderer-langer-satz-2026';

    private const string HOST = 'belege.example.org';

    private ServerCrypto $crypto;

    private int $adminId;

    private RecoveryKey $recoveryKey;

    private FakeMailTransport $mailTransport;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        /** @var array{server_key: string} $config */
        $config = self::configData();
        $this->crypto = new ServerCrypto((string) base64_decode($config['server_key'], true));
        $erstellt = new FirstAdminSetup($this->pdo())->create(self::ADMIN, 'Anna Admin', self::ADMIN_PW, $config['server_key']);
        $this->adminId = $erstellt['userId'];
        $this->recoveryKey = $erstellt['recoveryKey'];
        // The second factor has its own suite; here it would only stand
        // between the password and the pages under test.
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$this->adminId]);

        $this->mailTransport = new FakeMailTransport(array_fill(0, 40, true));
        $this->mailSettings()->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein');

        // The one admin has forgotten their password: reset by mail, a new
        // key pair, the grant gone - exactly the situation the recovery key
        // exists for.
        $this->zuruecksetzen();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testDerRichtigeSchluesselEntsperrtDenTresorUndErteiltEineFreigabe(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);
        self::assertNull($admin['vault'], 'Nach dem Reset ist noch keine Freigabe da.');

        // Typed sloppily: lower case, no separators, O instead of 0.
        $eingabe = strtr(strtolower(str_replace(' ', '', $this->recoveryKey->formatted())), ['0' => 'o']);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', ['schluessel' => $eingabe]));

        self::assertSame('/admin/tresor', $antwort->headers['Location'] ?? null);
        $cookie = self::vaultCookie($antwort);
        self::assertNotNull($cookie, 'Der Tresor wird sofort in dieser Sitzung entsperrt.');

        // $_SESSION already holds what the request above just stored there
        // (alsAdmin() leaves it as the action left it) - the vault cookie
        // alone opens nothing without the session it was stored into.
        $vault = new SessionVault()->unlock($cookie);
        self::assertNotNull($vault);
        $schluessel = DataKey::generate();
        self::assertTrue($vault->openDataKey($vault->sealDataKey($schluessel))->equals($schluessel));

        $grant = $this->pdo()->query('SELECT granted_by FROM vault_grant WHERE user_id = ' . $this->adminId)->fetchColumn();
        self::assertSame($this->adminId, (int) $grant, 'Die Freigabe geht an das eigene Konto.');

        // Audited, and every admin who can grant is told - here just the one.
        // Read before the next login, which would add its own "login.erfolg"
        // row for the same entity on top.
        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'user', entityId: $this->adminId), null, 10);
        self::assertSame(AuditAction::TresorWiederhergestellt->value, $zeilen[0]->action);
        self::assertSame($this->adminId, $zeilen[0]->userId);
        self::assertStringContainsString('Wiederherstellungsschlüssel des Vereins entsperrt', $this->letzteMail());

        // The next login has a grant again.
        $erneut = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);
        self::assertNotNull($erneut['vault']);
    }

    public function testEinMistippterSchluesselWirdAbgelehnt(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);

        $richtig = str_replace(' ', '', $this->recoveryKey->formatted());
        $verfaelscht = $richtig[30] === 'A' ? substr_replace($richtig, 'B', 30, 1) : substr_replace($richtig, 'A', 30, 1);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', ['schluessel' => $verfaelscht]));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('falsch eingegeben', $antwort->body);
        self::assertNull(self::vaultCookie($antwort));
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($this->adminId));

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'user', entityId: $this->adminId), null, 10);
        self::assertSame(AuditAction::TresorWiederherstellungFehlgeschlagen->value, $zeilen[0]->action);
        self::assertSame([], $this->mailTransport->gesendete, 'Ein Fehlschlag löst keine Mail aus.');
    }

    public function testEinSchluesselEinerAnderenInstallationWirdAbgelehnt(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);
        $fremd = RecoveryKey::forVault(Vault::create())->formatted();

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', ['schluessel' => $fremd]));

        self::assertStringContainsString('gehört nicht zu dieser Installation', $antwort->body);
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($this->adminId));
    }

    public function testNachZuVielenVersuchenIstAuchDerRichtigeSchluesselGesperrt(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);
        $falsch = str_replace(' ', '', $this->recoveryKey->formatted());
        $falsch = $falsch[30] === 'A' ? substr_replace($falsch, 'B', 30, 1) : substr_replace($falsch, 'A', 30, 1);

        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_ACCOUNT; $i++) {
            $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', ['schluessel' => $falsch]));
        }

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', [
            'schluessel' => $this->recoveryKey->formatted(),
        ]));

        self::assertStringContainsString('Zu viele Versuche', $antwort->body);
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($this->adminId));
    }

    public function testEinKontoMitBestehenderFreigabeBekommtNurDieSitzungEntsperrt(): void
    {
        // Grant restored the ordinary way first - the account is no longer
        // "ausstehend", only this particular session is locked.
        $vaults = new VaultRepository($this->pdo());
        $aktuell = $vaults->current();
        self::assertNotNull($aktuell);
        $tresor = RecoveryKey::parse($this->recoveryKey->formatted())->openVault($aktuell->publicKey(), $aktuell->version);
        $this->verwaltung()->freigeben($this->adminId, $tresor, $this->adminId);
        $vorherigerGrant = $this->pdo()->query('SELECT granted_at FROM vault_grant WHERE user_id = ' . $this->adminId)->fetchColumn();

        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);
        self::assertNotNull($admin['vault'], 'Die Freigabe wirkt schon ab dieser Anmeldung.');

        // A session without its cookie: logged in, grant present, vault locked.
        $_SESSION = $admin['session'];
        $antwort = $this->post('/admin/wiederherstellen', ['schluessel' => $this->recoveryKey->formatted()]);

        self::assertSame('/admin/tresor', $antwort->headers['Location'] ?? null);
        self::assertNotNull(self::vaultCookie($antwort));
        self::assertSame(
            $vorherigerGrant,
            $this->pdo()->query('SELECT granted_at FROM vault_grant WHERE user_id = ' . $this->adminId)->fetchColumn(),
            'Kein zweiter Grant.',
        );

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'user', entityId: $this->adminId), null, 10);
        self::assertSame(AuditAction::TresorWiederhergestellt->value, $zeilen[0]->action);
    }

    public function testNachDerWiederherstellungKannEinAnderesKontoFreigegebenWerden(): void
    {
        $wartend = $this->aktiverBenutzer('kasse@example.org');
        $admin = $this->anmelden(self::ADMIN, self::NEUES_PASSWORT);

        $wiederhergestellt = $this->alsAdmin($admin, fn() => $this->post('/admin/wiederherstellen', [
            'schluessel' => $this->recoveryKey->formatted(),
        ]));
        $cookie = self::vaultCookie($wiederhergestellt);
        self::assertNotNull($cookie);

        // $_SESSION already holds what the recovery above just stored there.
        $freigabe = $this->post('/admin/tresor/' . $wartend . '/freigeben', [], $cookie);

        self::assertSame('/admin/tresor', $freigabe->headers['Location'] ?? null);
        self::assertNotNull(new VaultGrantRepository($this->pdo())->forUser($wartend));
    }

    // ----------------------------------------------------------- scaffolding

    private function zuruecksetzen(): void
    {
        $reset = $this->resetService()->request(self::ADMIN, '198.51.100.7');
        self::assertNotNull($reset->token);
        $this->pdo()->exec('DELETE FROM mail_queue');

        $session = new Session();
        $session->start();
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/anmelden/passwort-neu', post: [
            '_csrf' => $session->csrfToken(),
            'token' => $reset->token,
            'passwort' => self::NEUES_PASSWORT,
            'passwort_wiederholung' => self::NEUES_PASSWORT,
            'verstanden' => '1',
        ], headers: ['host' => self::HOST], ip: '198.51.100.7'));
        self::assertSame('/anmelden', $antwort->headers['Location'] ?? null);
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($this->adminId));
        $this->pdo()->exec('DELETE FROM mail_queue');
        $this->mailTransport->gesendete = [];
        $_SESSION = [];
    }

    /**
     * An invited-and-activated account, no grant yet.
     */
    private function aktiverBenutzer(string $email): int
    {
        $zuweisung = new AccessAssignment($this->pdo(), new RoleRepository($this->pdo()), new UserAccessRepository($this->pdo()), new UserRepository($this->pdo()));
        $rolle = new RoleRepository($this->pdo())->findSystem(SystemRole::Finanzen);
        self::assertNotNull($rolle);
        $einladung = new Invitation($this->pdo(), $this->crypto, new PasswordHasher(), $this->policy(), $zuweisung);
        $ergebnis = $einladung->invite($email, 'Karl Kasse', [$rolle->id]);
        self::assertTrue($einladung->complete($ergebnis['token'], self::NEUES_PASSWORT, self::NEUES_PASSWORT)->istErfolg());
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$ergebnis['userId']]);

        return $ergebnis['userId'];
    }

    /**
     * Logs in from a fresh session and hands back that session and its vault
     * cookie (null when the vault stayed closed).
     *
     * @return array{session: array<mixed>, vault: ?string}
     */
    private function anmelden(string $email, string $passwort): array
    {
        $_SESSION = [];
        $session = new Session();
        $session->start();

        $antwort = $this->dispatch(new Request(
            HttpMethod::Post,
            '/anmelden',
            post: ['_csrf' => $session->csrfToken(), 'email' => $email, 'passwort' => $passwort],
            ip: '198.51.100.7',
        ));
        $ergebnis = ['session' => $_SESSION, 'vault' => self::vaultCookie($antwort)];
        $_SESSION = [];

        return $ergebnis;
    }

    /**
     * @param array{session: array<mixed>, vault: ?string} $admin
     * @param \Closure(): Response $aktion
     */
    private function alsAdmin(array $admin, \Closure $aktion): Response
    {
        $_SESSION = $admin['session'];

        return $aktion();
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder, ?string $vaultCookie = null): Response
    {
        return $this->dispatch(new Request(
            HttpMethod::Post,
            $pfad,
            post: [...$felder, '_csrf' => new Session()->csrfToken()],
            headers: ['host' => self::HOST],
            ip: '198.51.100.7',
            cookies: self::cookies($vaultCookie),
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function cookies(?string $vaultCookie): array
    {
        return $vaultCookie === null ? [] : [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => $vaultCookie];
    }

    private static function vaultCookie(Response $antwort): ?string
    {
        $header = $antwort->headers['Set-Cookie'] ?? null;
        $name = Cookie::vaultKey('x', Request::httpsFromGlobals())->name;
        if (!is_string($header) || preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $header, $treffer) !== 1) {
            return null;
        }

        return $treffer[1] === '' ? null : $treffer[1];
    }

    private function letzteMail(): string
    {
        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail, 'Es wurde keine Mail versendet.');

        return $mail->body;
    }

    private function mailSettings(): MailSettingsRepository
    {
        return new MailSettingsRepository(new SettingRepository($this->pdo()), $this->crypto);
    }

    private function mailer(): Mailer
    {
        return new Mailer(
            new MailQueueRepository($this->pdo(), $this->crypto),
            $this->mailSettings(),
            new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
            $this->mailTransport,
        );
    }

    private function policy(): PasswordPolicy
    {
        return new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt');
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS);
    }

    private function verwaltung(): UserAdministration
    {
        return new UserAdministration($this->pdo(), $this->crypto);
    }

    private function freigabeHinweis(): FreigabeBenachrichtigung
    {
        return new FreigabeBenachrichtigung($this->mailer(), $this->crypto, $this->verwaltung());
    }

    private function resetService(): PasswordReset
    {
        return new PasswordReset($this->pdo(), $this->crypto, new PasswordHasher(), $this->policy(), $this->limiter());
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * The real route table with the real guard and the controllers this test
     * drives; everything else is unreachable.
     */
    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $crypto = $this->crypto;
        $mfaService = new MfaService(
            new MfaTotpRepository($pdo),
            new MfaEmailCodeRepository($pdo),
            new MfaBackupCodeRepository($pdo),
            new TrustedDeviceRepository($pdo),
            $crypto,
            new RateLimiter(new RateLimitRepository($pdo), MfaService::WINDOW_SECONDS),
        );

        $audit = new AuditLog(new AuditLogRepository($pdo), new VaultRepository($pdo), $crypto);

        $auth = fn(): AuthController => new AuthController(
            $view,
            new Session(),
            new SessionVault(),
            new PendingLogin(),
            new LoginCompleter(new Session(), new SessionVault()),
            fn(): LoginService => new LoginService(
                new UserRepository($pdo),
                new UserKeyRepository($pdo),
                new VaultGrantRepository($pdo),
                new VaultRepository($pdo),
                $crypto,
                $this->limiter(),
                new PasswordHasher(),
            ),
            fn(): MfaService => $mfaService,
            fn(): AuditLog => $audit,
        );
        $passwort = fn(): PasswordController => new PasswordController(
            $view,
            new Session(),
            new SessionVault(),
            fn(): PasswordToolbox => new PasswordToolbox(
                $this->resetService(),
                new UserRepository($pdo),
                $this->mailer(),
                $this->mailSettings(),
                $crypto,
                $audit,
                null,
                $this->freigabeHinweis(),
            ),
        );
        $tresor = fn(): VaultGrantController => new VaultGrantController(
            $view,
            new Session(),
            new SessionVault(),
            new UserRepository($pdo),
            new VaultRepository($pdo),
            new VaultGrantRepository($pdo),
            $this->verwaltung(),
            $this->mailer(),
            $crypto,
            $audit,
        );
        $wiederherstellung = fn(): VaultRecoveryController => new VaultRecoveryController(
            $view,
            new Session(),
            new SessionVault(),
            new VaultRecovery(
                new VaultRepository($pdo),
                new VaultGrantRepository($pdo),
                $this->verwaltung(),
                $this->limiter(),
            ),
            $this->verwaltung(),
            $this->mailer(),
            $crypto,
            $audit,
        );
        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            fn(): SessionTimeouts => SessionTimeouts::fromSettings(new SettingRepository($pdo)),
            function (int $userId) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($userId);

                return $user === null
                    ? null
                    : new SessionUser($user, $this->crypto->decrypt($user->displayNameEnc), new UserAccessRepository($pdo)->berechtigungen($userId));
            },
            function () use ($pdo): int {
                $vault = new VaultRepository($pdo)->current();

                return $vault === null ? 0 : new VaultGrantRepository($pdo)->countPending($vault->version, new \DateTimeImmutable());
            },
        );
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $auth,
            $guard,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $passwort,
            $unerreichbar,
            $unerreichbar,
            $tresor,
            $wiederherstellung,
            $unerreichbar,
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
