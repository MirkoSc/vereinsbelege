<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AuthController;
use App\App\LoginCompleter;
use App\App\MfaController;
use App\App\MfaToolbox;
use App\App\SecurityController;
use App\Config\Paths;
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
use App\Repository\SettingRepository;
use App\Repository\TrustedDeviceRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\LoginService;
use App\Service\Account\MfaEnrollment;
use App\Service\Account\MfaService;
use App\Service\Account\PasswordChange;
use App\Service\Account\PasswordHasher;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PendingLogin;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Account\Totp;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
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
 * The second factor end to end (issue #17/M3-4, docs/spec/01-sicherheit.md
 * section 3), through the real route table - the same technique
 * tests/Integration/LoginFlowTest.php uses: $_SESSION as the session file,
 * the `Set-Cookie` of one response fed into the next request's cookies.
 *
 * What this proves that a unit test cannot: that the vault genuinely stays
 * closed - no `__Host-vk` cookie, no session id - until the second factor is
 * answered, even though the password was right and the vault itself
 * unlocked internally the moment it was (docs/spec/01-sicherheit.md
 * section 2, "M3-4 hängt den Schritt zwischen 'Passwort stimmt' und 'Tresor
 * entsperrt' ein").
 */
final class TwoFactorFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'kasse@example.org';

    private const string PASSWORT = 'korrekt-pferd-batterie-heftklammer';

    private ServerCrypto $crypto;

    private int $userId;

    private FakeMailTransport $mailTransport;

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

        $this->mailTransport = new FakeMailTransport(array_fill(0, 20, true));
        new MailSettingsRepository(new SettingRepository($this->pdo()), $this->crypto)
            ->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------------- TOTP

    public function testEineVollstaendigeTotpAnmeldungOeffnetDenTresor(): void
    {
        $secret = $this->totpEinrichten();

        $pendingCookie = self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        self::assertNotNull($pendingCookie, 'Das Passwort allein setzt das Pending-Cookie.');

        $antwort = $this->bestaetigen($this->totpCode($secret), [self::pendingCookieName() => $pendingCookie]);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
        self::assertNotNull(self::cookieValue($antwort, self::vaultCookieName()), 'Der Tresor-Schlüssel wird erst nach 2FA gesetzt.');
        self::assertSame($this->userId, new Session()->userId());
    }

    public function testEinFalscherTotpCodeSchlaegtFehl(): void
    {
        $this->totpEinrichten();
        $pendingCookie = self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());

        $antwort = $this->bestaetigen('000000', [self::pendingCookieName() => (string) $pendingCookie]);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('ungültig', $antwort->body);
        self::assertNull(new Session()->userId());
        self::assertArrayNotHasKey('Set-Cookie', $antwort->headers);
    }

    // -------------------------------------------------------- E-Mail-Code

    public function testEineVollstaendigeEMailAnmeldungOeffnetDenTresor(): void
    {
        $this->emailMethodeEinrichten();

        $pendingCookie = self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        self::assertNotNull($pendingCookie);
        $cookies = [self::pendingCookieName() => $pendingCookie];

        $this->codeSenden($cookies);
        $code = $this->letzterVersendeterCode();

        $antwort = $this->bestaetigen($code, $cookies);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
        self::assertNotNull(self::cookieValue($antwort, self::vaultCookieName()));
    }

    public function testEinAbgelaufenerEMailCodeWirdAbgelehnt(): void
    {
        $this->emailMethodeEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        $this->codeSenden($cookies);
        $code = $this->letzterVersendeterCode();

        // App\App\MfaController never threads a test clock through - the
        // way this suite (like tests/Integration/LoginFlowTest.php) fakes
        // elapsed time is by rewriting the stored row directly.
        $this->pdo()->prepare('UPDATE mfa_email_code SET expires_at = ? WHERE user_id = ?')
            ->execute([(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'), $this->userId]);

        $antwort = $this->bestaetigen($code, $cookies);

        self::assertStringContainsString('ungültig oder abgelaufen', $antwort->body);
        self::assertNull(new Session()->userId());
    }

    public function testNachFuenfFalschenEMailCodesIstAuchDerRichtigeUngueltig(): void
    {
        $this->emailMethodeEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        $this->codeSenden($cookies);
        $richtigerCode = $this->letzterVersendeterCode();

        for ($i = 0; $i < 5; $i++) {
            $this->bestaetigen('000000', $cookies);
        }

        $antwort = $this->bestaetigen($richtigerCode, $cookies);

        self::assertStringContainsString('ungültig oder abgelaufen', $antwort->body);
        self::assertNull(new Session()->userId());
    }

    public function testEinTotpKontoKannAufEMailAusweichen(): void
    {
        $this->totpEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        $this->codeSenden($cookies);
        $code = $this->letzterVersendeterCode();

        $antwort = $this->bestaetigen($code, $cookies);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
    }

    // -------------------------------------------------------- Backup-Codes

    public function testEinBackupCodeFunktioniertGenauEinmal(): void
    {
        $codes = $this->totpEinrichtenMitCodes();
        $code = $codes[0];
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        $ersterVersuch = $this->backupCode($code, $cookies);
        self::assertSame('/app', $ersterVersuch->headers['Location'] ?? null);
        self::assertNotNull(self::cookieValue($ersterVersuch, self::vaultCookieName()));

        // Erneut anmelden, um wieder ein Pending zu bekommen.
        new Session()->destroy();
        new SessionVault()->clear();
        $_SESSION = [];
        $pendingCookie2 = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());

        $zweiterVersuch = $this->backupCode($code, [self::pendingCookieName() => $pendingCookie2]);
        self::assertStringContainsString('ungültig oder bereits verbraucht', $zweiterVersuch->body);
    }

    // --------------------------------------------------------- Gerät merken

    public function testEinGemerktesGeraetUeberspringtDieAbfrage(): void
    {
        $secret = $this->totpEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());

        $bestaetigt = $this->bestaetigen(
            $this->totpCode($secret),
            [self::pendingCookieName() => $pendingCookie],
            geraetMerken: true,
        );
        $deviceCookie = self::cookieValue($bestaetigt, self::trustedDeviceCookieName());
        self::assertNotNull($deviceCookie, 'Das Gerät-Cookie wird gesetzt.');

        // Frische Sitzung, als käme derselbe Browser wieder.
        new Session()->destroy();
        new SessionVault()->clear();
        $_SESSION = [];

        $erneuterLogin = $this->passwortAnmelden(cookies: [self::trustedDeviceCookieName() => $deviceCookie]);

        self::assertSame('/app', $erneuterLogin->headers['Location'] ?? null, 'Kein Pending mehr nötig.');
        self::assertNotNull(self::cookieValue($erneuterLogin, self::vaultCookieName()));
    }

    public function testEinAbgelaufenesGeraetVerlangtWiederDenZweitenFaktor(): void
    {
        $secret = $this->totpEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());

        $bestaetigt = $this->bestaetigen(
            $this->totpCode($secret),
            [self::pendingCookieName() => $pendingCookie],
            geraetMerken: true,
        );
        $deviceCookie = (string) self::cookieValue($bestaetigt, self::trustedDeviceCookieName());

        new Session()->destroy();
        new SessionVault()->clear();
        $_SESSION = [];

        $this->pdo()->prepare('UPDATE trusted_device SET expires_at = ? WHERE user_id = ?')
            ->execute([(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'), $this->userId]);

        $erneuterLogin = $this->passwortAnmelden(cookies: [self::trustedDeviceCookieName() => $deviceCookie]);

        self::assertSame('/anmelden/bestaetigen', $erneuterLogin->headers['Location'] ?? null, 'Der zweite Faktor wird wieder verlangt.');
    }

    // ----------------------------------------------- ohne bestandene 2FA

    public function testOhneBestandeneZweiFaktorAnmeldungBleibtDerTresorZu(): void
    {
        $this->totpEinrichten();
        $this->passwortAnmelden();

        self::assertNull(new Session()->userId(), 'Keine echte Sitzung, solange 2FA offen ist.');
        self::assertFalse(new SessionVault()->isStored());

        $antwort = $this->dispatch($this->get('/app'));
        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', (string) ($antwort->headers['Location'] ?? ''));
    }

    // ------------------------------------------------------- Einrichtung

    public function testEinrichtungWirdErzwungen(): void
    {
        // Frisch angelegtes Konto: mfa_required = 1 (Vorgabe), kein Faktor.
        $this->passwortAnmelden();

        self::assertSame($this->userId, new Session()->userId(), 'Ohne konfigurierten Faktor wird die Anmeldung abgeschlossen.');

        $antwort = $this->dispatch($this->get('/app'));
        self::assertSame(302, $antwort->status);
        self::assertSame('/app/sicherheit/einrichten', $antwort->headers['Location'] ?? null);

        // Die Einrichtungsseite selbst bleibt erreichbar.
        self::assertSame(200, $this->dispatch($this->get('/app/sicherheit/einrichten'))->status);
    }

    public function testDieVollstaendigeEinrichtungUeberDasFormular(): void
    {
        $this->passwortAnmelden();
        $session = new Session();
        $session->start();

        $gestartet = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/sicherheit/einrichten/totp/starten',
            post: ['_csrf' => $session->csrfToken()],
        ));
        self::assertSame(200, $gestartet->status);
        self::assertStringContainsString('<svg', $gestartet->body, 'Der QR-Code wird eingebettet.');

        $secretEnc = $this->pdo()->query('SELECT secret_enc FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn();
        self::assertIsString($secretEnc);
        $secret = $this->crypto->decrypt($secretEnc);

        $falsch = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/sicherheit/einrichten/totp/bestaetigen',
            post: ['_csrf' => $session->csrfToken(), 'code' => '000000'],
        ));
        self::assertStringContainsString('stimmt nicht', $falsch->body);

        $richtig = $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/sicherheit/einrichten/totp/bestaetigen',
            post: ['_csrf' => $session->csrfToken(), 'code' => Totp::code($secret, new \DateTimeImmutable())],
        ));
        self::assertSame(200, $richtig->status);
        self::assertStringContainsString('eingerichtet', $richtig->body);
        // Zehn Backup-Codes, Format XXXXX-XXXXX.
        self::assertSame(10, preg_match_all('/[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}/', $richtig->body));

        // Jetzt erreichbar, ohne Umleitung.
        self::assertSame(200, $this->dispatch($this->get('/app'))->status);
    }

    // ------------------------------------------------------- Rate-Limit

    public function testZuVieleFehlversucheSperrenAuchDenRichtigenCode(): void
    {
        $secret = $this->totpEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        for ($i = 0; $i < MfaService::LIMIT_PER_ACCOUNT; $i++) {
            $this->bestaetigen('000000', $cookies);
        }

        $antwort = $this->bestaetigen($this->totpCode($secret), $cookies);

        self::assertStringContainsString('Zu viele Versuche', $antwort->body);
        self::assertNull(new Session()->userId());
    }

    // ----------------------------------------------------------- Helfer

    private function mfaEnrollment(): MfaEnrollment
    {
        return new MfaEnrollment(
            new MfaTotpRepository($this->pdo()),
            new MfaBackupCodeRepository($this->pdo()),
            new UserRepository($this->pdo()),
            $this->crypto,
        );
    }

    private function totpEinrichten(?\DateTimeImmutable $now = null): string
    {
        $enrollment = $this->mfaEnrollment();
        $secret = $enrollment->startTotp($this->userId, $now);
        $now ??= new \DateTimeImmutable();
        $codes = $enrollment->confirmTotp($this->userId, Totp::code($secret, $now), $now);
        self::assertNotNull($codes, 'Testvorbereitung: TOTP-Einrichtung muss gelingen.');

        return $secret;
    }

    /**
     * @return list<string>
     */
    private function totpEinrichtenMitCodes(): array
    {
        $enrollment = $this->mfaEnrollment();
        $secret = $enrollment->startTotp($this->userId);
        $codes = $enrollment->confirmTotp($this->userId, Totp::code($secret, new \DateTimeImmutable()));
        self::assertNotNull($codes);

        return $codes;
    }

    private function emailMethodeEinrichten(): void
    {
        $this->mfaEnrollment()->commitEmailMethod($this->userId);
    }

    /**
     * @param array<string, string> $cookies
     */
    /**
     * @param array<string, string> $cookies
     */
    private function passwortAnmelden(array $cookies = []): Response
    {
        $session = new Session();
        $session->start();

        $post = ['_csrf' => $session->csrfToken(), 'email' => self::EMAIL, 'passwort' => self::PASSWORT];

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden', post: $post, ip: '198.51.100.7', cookies: $cookies));
    }

    /**
     * A code for the current moment - App\App\MfaController verifies against
     * real wall-clock time, so this suite (like the rest of it) never tries
     * to feed a test clock through the HTTP layer.
     */
    private function totpCode(string $secret): string
    {
        return Totp::code($secret, new \DateTimeImmutable());
    }

    /**
     * @param array<string, string> $cookies
     */
    private function bestaetigen(string $code, array $cookies, bool $geraetMerken = false): Response
    {
        $session = new Session();
        $session->start();

        $post = ['_csrf' => $session->csrfToken(), 'code' => $code];
        if ($geraetMerken) {
            $post['geraet_merken'] = '1';
        }

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden/bestaetigen', post: $post, ip: '198.51.100.7', cookies: $cookies));
    }

    /**
     * @param array<string, string> $cookies
     */
    private function backupCode(string $code, array $cookies): Response
    {
        $session = new Session();
        $session->start();

        $post = ['_csrf' => $session->csrfToken(), 'code' => $code];

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden/backup-code', post: $post, ip: '198.51.100.7', cookies: $cookies));
    }

    /**
     * @param array<string, string> $cookies
     */
    private function codeSenden(array $cookies): Response
    {
        $session = new Session();
        $session->start();

        $post = ['_csrf' => $session->csrfToken()];

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden/code-senden', post: $post, ip: '198.51.100.7', cookies: $cookies));
    }

    private function letzterVersendeterCode(): string
    {
        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail, 'Es wurde keine Mail versendet.');
        self::assertMatchesRegularExpression('/\d{6}/', $mail->body, 'Die Mail enthält keinen Code.');
        preg_match('/\d{6}/', $mail->body, $treffer);

        return $treffer[0];
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

    private static function pendingCookieName(): string
    {
        return PendingLogin::INSECURE_COOKIE;
    }

    private static function vaultCookieName(): string
    {
        return Cookie::vaultKey('x', Request::httpsFromGlobals())->name;
    }

    private static function trustedDeviceCookieName(): string
    {
        return Cookie::trustedDevice('x', Request::httpsFromGlobals(), 1)->name;
    }

    private static function cookieValue(Response $antwort, string $name): ?string
    {
        $header = $antwort->headers['Set-Cookie'] ?? null;
        if (is_string($header) && preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $header, $treffer) === 1) {
            return $treffer[1] === '' ? null : $treffer[1];
        }

        foreach ($antwort->additionalCookies as $cookie) {
            if ($cookie->name === $name) {
                return $cookie->value === '' ? null : $cookie->value;
            }
        }

        return null;
    }

    /**
     * The real route table with the real guard, auth, mfa and security
     * controllers - a test that built its own routes would prove nothing
     * about what the application serves.
     */
    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $crypto = $this->crypto;
        $mailTransport = $this->mailTransport;

        $mfaServiceFor = static fn(\PDO $pdo): MfaService => new MfaService(
            new MfaTotpRepository($pdo),
            new MfaEmailCodeRepository($pdo),
            new MfaBackupCodeRepository($pdo),
            new TrustedDeviceRepository($pdo),
            $crypto,
            new RateLimiter(new RateLimitRepository($pdo), MfaService::WINDOW_SECONDS),
        );
        $mailerFor = static fn(\PDO $pdo): Mailer => new Mailer(
            new MailQueueRepository($pdo, $crypto),
            new MailSettingsRepository(new SettingRepository($pdo), $crypto),
            new MailTemplates($paths->viewsDir() . '/mail'),
            $mailTransport,
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
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
                new PasswordHasher(),
            ),
            fn(): MfaService => $mfaServiceFor($pdo),
            fn(): AuditLog => $audit,
        );

        $mfaController = fn(): MfaController => new MfaController(
            $view,
            new Session(),
            new PendingLogin(),
            new LoginCompleter(new Session(), new SessionVault()),
            fn(): MfaToolbox => new MfaToolbox($mfaServiceFor($pdo), new UserRepository($pdo), $mailerFor($pdo), new SettingRepository($pdo), $crypto, $audit),
        );

        $sicherheit = fn(): SecurityController => new SecurityController(
            $view,
            new Session(),
            $mfaServiceFor($pdo),
            new MfaEnrollment(new MfaTotpRepository($pdo), new MfaBackupCodeRepository($pdo), new UserRepository($pdo), $crypto),
            new UserRepository($pdo),
            $mailerFor($pdo),
            $crypto,
            new PasswordChange(
                $pdo,
                new PasswordHasher(),
                new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt'),
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
            ),
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
                    : new SessionUser(
                        $user,
                        $this->crypto->decrypt($user->displayNameEnc),
                        new UserAccessRepository($pdo)->berechtigungen($userId),
                    );
            },
        );

        // Everything behind the guard that this test never reaches.
        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route hätte gesperrt sein müssen.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $auth,
            $guard,
            $mfaController,
            $sicherheit,
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
            $unerreichbar,
        );

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            debug: false,
        );
    }
}
