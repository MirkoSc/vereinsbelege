<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AuthController;
use App\App\LoginCompleter;
use App\Config\Paths;
use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Installer\FirstAdminSetup;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\LoginService;
use App\Service\Account\PasswordHasher;
use App\Service\Account\PendingLogin;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;
use App\View\Area;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The login of M3-3 (issue #16) end to end, through the real route table:
 * the account the installer created in M3-2 signs in, the vault unlocks in
 * the session, and everything behind the login is actually closed until it
 * does (docs/spec/01-sicherheit.md sections 2 and 3).
 *
 * The browser's two halves are simulated by hand - $_SESSION is the session
 * file, and the `Set-Cookie` of one response is fed into the next request.
 * That is exactly what makes the central claim testable: take the cookie
 * away and the very same session can no longer decrypt anything.
 */
final class LoginFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'kasse@example.org';

    private const string PASSWORT = 'korrekt-pferd-batterie-heftklammer';

    private ServerCrypto $crypto;

    private int $userId;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        /** @var array{server_key: string} $config */
        $config = self::configData();
        $this->crypto = new ServerCrypto((string) base64_decode($config['server_key'], true));

        $ergebnis = new FirstAdminSetup($this->pdo())->create(
            self::EMAIL,
            'Katrin Kassenwart',
            self::PASSWORT,
            $config['server_key'],
        );
        $this->userId = $ergebnis['userId'];
        $this->vault = Vault::locked(
            (string) new VaultRepository($this->pdo())->current()?->publicKey(),
            $ergebnis['vaultVersion'],
        );

        // This suite is the M3-3 login layer, deliberately independent of
        // the second factor M3-4 (issue #17) adds on top of it - that layer
        // has its own suite, tests/Integration/TwoFactorFlowTest.php. Every
        // account defaults to `mfa_required` (migrations/006_user.sql), so
        // without this the tests below would all hit the "no factor set up
        // yet" enrollment redirect (App\Http\LoginGuard) instead of the
        // plain password/vault behaviour they exist to check.
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ---------------------------------------------------------------- login

    public function testTheInstallerAccountCanSignIn(): void
    {
        $antwort = $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertSame(302, $antwort->status);
        self::assertSame('/app', $antwort->headers['Location']);
        self::assertSame($this->userId, new Session()->userId());
    }

    public function testTheEmailIsFoundThroughItsBlindIndexRegardlessOfCase(): void
    {
        $antwort = $this->anmelden('  KASSE@Example.ORG  ', self::PASSWORT);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
    }

    public function testASuccessfulLoginRecordsTheTime(): void
    {
        self::assertNull(new UserRepository($this->pdo())->findById($this->userId)?->lastLoginAt);

        $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertNotNull(new UserRepository($this->pdo())->findById($this->userId)?->lastLoginAt);
    }

    /** Session fixation: the id that carries the account is a new one. */
    public function testTheSessionIdIsRegeneratedOnLogin(): void
    {
        $session = new Session();
        $session->start();
        $vorher = session_id();

        $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertNotSame($vorher, session_id());
    }

    // ------------------------------------------------- vault in the session

    public function testTheLoginUnlocksTheVaultInTheSession(): void
    {
        // Sealed before anybody logged in - what the public submission does.
        $schluessel = DataKey::generate();
        $versiegelt = $this->vault->sealDataKey($schluessel);

        $cookie = self::vaultCookieOf($this->anmelden(self::EMAIL, self::PASSWORT));
        self::assertNotNull($cookie, 'Der Login setzt den Sitzungsschlüssel als Cookie.');

        $entsperrt = new SessionVault()->unlock($cookie);

        self::assertNotNull($entsperrt);
        self::assertTrue($schluessel->equals($entsperrt->openDataKey($versiegelt)));
    }

    /**
     * The acceptance criterion of issue #16: no `__Host-vk` cookie, no
     * decryption - even though the session itself is perfectly intact and
     * still logged in.
     */
    public function testWithoutTheVaultCookieTheSameSessionCannotDecrypt(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);

        $ohneCookie = new SessionVault()->unlock(null);

        self::assertSame($this->userId, new Session()->userId(), 'Angemeldet ist die Sitzung weiterhin.');
        self::assertNull($ohneCookie, 'Entsperren geht ohne das Cookie nicht.');

        // And the protected page still renders - being unable to decrypt is
        // not the same as being logged out.
        self::assertSame(200, $this->dispatch($this->get('/app'))->status);
    }

    public function testTheSessionFileAloneDoesNotContainTheVaultKey(): void
    {
        $cookie = self::vaultCookieOf($this->anmelden(self::EMAIL, self::PASSWORT));
        self::assertNotNull($cookie);

        $geheim = new SessionVault()->unlock($cookie)?->secretKey();

        self::assertNotNull($geheim);
        self::assertStringNotContainsString($geheim, serialize($_SESSION));
    }

    /**
     * A user without a vault grant signs in normally and is told why the
     * vault stays closed (docs/spec/01-sicherheit.md section 2, the state
     * after a password reset).
     */
    public function testAnAccountWithoutAGrantSignsInWithoutAVault(): void
    {
        $this->pdo()->prepare('DELETE FROM vault_grant WHERE user_id = ?')->execute([$this->userId]);

        $antwort = $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
        self::assertNull(self::vaultCookieOf($antwort), 'Ohne Freigabe gibt es keinen Sitzungsschlüssel.');
        self::assertStringContainsString('Freigabe', (string) ($_SESSION['flash']['text'] ?? ''));
    }

    // ------------------------------------------------------ generic answers

    /**
     * No user enumeration (docs/spec/01-sicherheit.md section 3): every way
     * of failing has to produce the very same sentence.
     */
    #[DataProvider('fehlversuche')]
    public function testEveryFailedAttemptAnswersTheSameWay(string $email, string $passwort, ?string $vorbereitung): void
    {
        if ($vorbereitung !== null) {
            $this->pdo()->prepare($vorbereitung)->execute([$this->userId]);
        }

        $antwort = $this->anmelden($email, $passwort);

        self::assertSame(200, $antwort->status, 'Die Seite wird erneut gezeigt, nicht weitergeleitet.');
        self::assertStringContainsString(
            'E-Mail-Adresse oder Passwort ist falsch.',
            $antwort->body,
            'Jeder Fehlschlag sagt dasselbe.',
        );
        self::assertNull(new Session()->userId());
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function fehlversuche(): iterable
    {
        yield 'falsches Passwort' => [self::EMAIL, 'ganz-sicher-nicht-das-passwort', null];
        yield 'unbekannte Adresse' => ['niemand@example.org', self::PASSWORT, null];
        yield 'leere Eingabe' => ['', '', null];
        yield 'gesperrtes Konto' => [
            self::EMAIL,
            self::PASSWORT,
            "UPDATE `user` SET status = 'gesperrt' WHERE id = ?",
        ];
        yield 'abgelaufenes externes Konto' => [
            self::EMAIL,
            self::PASSWORT,
            "UPDATE `user` SET expires_at = '2020-01-01 00:00:00' WHERE id = ?",
        ];
    }

    public function testAFailedAttemptSetsNoVaultCookie(): void
    {
        $antwort = $this->anmelden(self::EMAIL, 'falsch-falsch-falsch');

        self::assertArrayNotHasKey('Set-Cookie', $antwort->headers);
    }

    // ----------------------------------------------------------- rate limit

    public function testTooManyFailedAttemptsCloseTheDoor(): void
    {
        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_ACCOUNT; $i++) {
            $this->anmelden(self::EMAIL, 'falsch-' . $i);
        }

        // Even the RIGHT password does not get through anymore - that is the
        // point of a limit per account.
        $antwort = $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertStringContainsString('Zu viele Anmeldeversuche', $antwort->body);
        self::assertNull(new Session()->userId());
    }

    public function testASuccessfulLoginClearsTheCounter(): void
    {
        $this->anmelden(self::EMAIL, 'falsch');
        $this->anmelden(self::EMAIL, 'auch-falsch');

        $this->anmelden(self::EMAIL, self::PASSWORT);

        self::assertSame(
            0,
            (int) $this->pdo()->query('SELECT COUNT(*) FROM rate_limit')->fetchColumn(),
            'Nach einem erfolgreichen Login zählt nichts mehr.',
        );
    }

    // --------------------------------------------------------------- logout

    public function testLogoutEndsTheSessionAndDeletesTheCookie(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);
        $session = new Session();
        $session->start();

        $antwort = $this->dispatch(new Request(
            method: HttpMethod::Post,
            path: '/abmelden',
            post: ['_csrf' => $session->csrfToken()],
        ));

        self::assertSame('/anmelden', $antwort->headers['Location'] ?? null);
        self::assertStringContainsString('Max-Age=0', (string) ($antwort->headers['Set-Cookie'] ?? ''));
        self::assertNull(new Session()->userId());
        self::assertFalse(new SessionVault()->isStored());
    }

    public function testLogoutWithoutATokenChangesNothing(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);

        $this->dispatch(new Request(HttpMethod::Post, '/abmelden'));

        self::assertSame($this->userId, new Session()->userId());
    }

    // ---------------------------------------------------------------- guard

    /**
     * @param string $pfad
     */
    #[DataProvider('geschuetzteSeiten')]
    public function testAProtectedPageSendsAnonymousVisitorsToTheLogin(string $pfad): void
    {
        $antwort = $this->dispatch($this->get($pfad));

        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', (string) ($antwort->headers['Location'] ?? ''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function geschuetzteSeiten(): iterable
    {
        yield '/app' => ['/app'];
        yield '/admin' => ['/admin'];
        yield '/admin/mail' => ['/admin/mail'];
        yield '/admin/speicher' => ['/admin/speicher'];
        yield '/admin/update' => ['/admin/update'];
        yield '/admin/designsystem' => ['/admin/designsystem'];
    }

    public function testTheLoginRemembersWhereTheVisitorWasHeaded(): void
    {
        $antwort = $this->dispatch($this->get('/admin/mail'));

        self::assertSame('/anmelden?weiter=%2Fadmin%2Fmail', $antwort->headers['Location'] ?? null);
    }

    public function testAfterTheLoginTheVisitorLandsWhereTheyWereHeaded(): void
    {
        $antwort = $this->anmelden(self::EMAIL, self::PASSWORT, weiter: '/admin/mail');

        self::assertSame('/admin/mail', $antwort->headers['Location'] ?? null);
    }

    /** An open redirect would turn the login into a phishing helper. */
    public function testAForeignRedirectTargetIsIgnored(): void
    {
        $antwort = $this->anmelden(self::EMAIL, self::PASSWORT, weiter: '//example.org/');

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
    }

    public function testTheUploadApiAnswers401InsteadOfRedirecting(): void
    {
        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/api/upload'));

        self::assertSame(401, $antwort->status);
        self::assertStringContainsString('application/json', $antwort->headers['Content-Type'] ?? '');
    }

    public function testTheLoginAndThePublicPagesStayOpen(): void
    {
        self::assertSame(200, $this->dispatch($this->get('/'))->status);
        self::assertSame(200, $this->dispatch($this->get('/anmelden'))->status);
    }

    public function testAProtectedPageOpensOnceSignedIn(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);

        $antwort = $this->dispatch($this->get('/app'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Katrin Kassenwart', $antwort->body, 'Der Kopf nennt den Zugang.');
        self::assertStringContainsString('action="/abmelden"', $antwort->body);
    }

    public function testAnIdleSessionIsSentBackToTheLogin(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);

        new SettingRepository($this->pdo())->set(SessionTimeouts::SETTING_IDLE, '1');
        $_SESSION['last_seen_at'] = time() - 60;

        $antwort = $this->dispatch($this->get('/app'));

        self::assertSame(302, $antwort->status);
        self::assertStringContainsString('abgelaufen=1', (string) ($antwort->headers['Location'] ?? ''));
        self::assertStringContainsString('Max-Age=0', (string) ($antwort->headers['Set-Cookie'] ?? ''));
        self::assertFalse(new SessionVault()->isStored(), 'Der Tresor ist aus der Sitzung entfernt.');
    }

    public function testASessionOlderThanTheAbsoluteLimitIsSentBackToTheLogin(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);
        $_SESSION['login_at'] = time() - SessionTimeouts::ABSOLUTE_DEFAULT - 1;

        self::assertSame(302, $this->dispatch($this->get('/app'))->status);
    }

    /** Locking an account has to take effect now, not at the next login. */
    public function testALockedAccountLosesItsRunningSession(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);
        $this->pdo()->prepare("UPDATE `user` SET status = 'gesperrt' WHERE id = ?")->execute([$this->userId]);

        $antwort = $this->dispatch($this->get('/app'));

        self::assertSame(302, $antwort->status);
        self::assertNull(new Session()->userId());
    }

    public function testSomebodyAlreadySignedInIsNotShownTheFormAgain(): void
    {
        $this->anmelden(self::EMAIL, self::PASSWORT);

        $antwort = $this->dispatch($this->get('/anmelden'));

        self::assertSame(302, $antwort->status);
        self::assertSame('/app', $antwort->headers['Location'] ?? null);
    }

    private function anmelden(string $email, string $passwort, ?string $weiter = null): Response
    {
        $session = new Session();
        $session->start();

        $post = ['_csrf' => $session->csrfToken(), 'email' => $email, 'passwort' => $passwort];
        if ($weiter !== null) {
            $post['weiter'] = $weiter;
        }

        $antwort = $this->dispatch(new Request(
            method: HttpMethod::Post,
            path: '/anmelden',
            post: $post,
            ip: '198.51.100.7',
        ));

        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private function get(string $pfad): Request
    {
        return new Request(HttpMethod::Get, $pfad, ip: '198.51.100.7');
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * The real route table with the real guard - a test that built its own
     * routes would prove nothing about what the application serves.
     */
    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();

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
                $this->crypto,
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
                new PasswordHasher(),
            ),
            // Never called: every account in this suite has mfa_required = 0
            // (setUp()), so App\App\AuthController::deviceIsTrusted() short-
            // circuits before it would use this.
            static fn(): never => throw new \LogicException('MfaService hätte hier nicht gebraucht werden dürfen.'),
        );

        $guard = fn(): LoginGuard => new LoginGuard(
            new Session(),
            $view,
            fn(): SessionTimeouts => SessionTimeouts::fromSettings(new SettingRepository($pdo)),
            function (int $userId) use ($pdo): ?SessionUser {
                $user = new UserRepository($pdo)->findById($userId);

                return $user === null
                    ? null
                    : new SessionUser(
                        $user,
                        $this->crypto->decrypt($user->displayNameEnc),
                        new UserAccessRepository($pdo)->berechtigungen($userId),
                    );
            },
        );

        // Everything behind the guard that this test never reaches: calling
        // one would mean the guard let a request through that it should not.
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route hätte gesperrt sein müssen.');

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
            $unerreichbar,
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: false,
        );
    }

    public function testTheLoginPageRendersInThePublicArea(): void
    {
        $antwort = $this->dispatch($this->get('/anmelden'));

        self::assertStringContainsString('bereich-' . Area::Oeffentlich->value, $antwort->body);
        self::assertStringNotContainsString('Hauptnavigation', $antwort->body, 'Kein Menü vor der Anmeldung.');
    }

    // ----------------------------------------------------------- scaffolding

    private static function vaultCookieOf(ResponseInterface $antwort): ?string
    {
        self::assertInstanceOf(Response::class, $antwort);
        $header = $antwort->headers['Set-Cookie'] ?? null;
        if ($header === null) {
            return null;
        }

        $name = Cookie::vaultKey('x', Request::httpsFromGlobals())->name;
        if (preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $header, $treffer) !== 1) {
            return null;
        }

        return $treffer[1] === '' ? null : $treffer[1];
    }
}
