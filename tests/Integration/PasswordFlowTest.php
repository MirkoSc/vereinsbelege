<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\App\AuthController;
use App\App\LoginCompleter;
use App\App\PasswordController;
use App\App\PasswordToolbox;
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
use App\Repository\AuthTokenRepository;
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
use App\Service\Account\PasswordReset;
use App\Service\Account\PendingLogin;
use App\Service\Account\SessionTimeouts;
use App\Service\Account\SessionUser;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Crypto\VaultGrant;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Support\FileLogger;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;
use App\View\View;

/**
 * Password change and reset end to end (issue #18/M3-5,
 * docs/spec/01-sicherheit.md sections 2 and 3), through the real route
 * table - the same technique as LoginFlowTest: $_SESSION is the session
 * file, the `Set-Cookie` of one response goes into the next request.
 *
 * The Pflicht-Test of spec 01 this covers: the user lifecycle "Passwort
 * ändern → Reset → erneute Freigabe" including "ohne Grant kein
 * Entschlüsseln", and the reset token (one use, expiry).
 */
final class PasswordFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'kasse@example.org';

    private const string PASSWORT = 'korrekt-pferd-batterie-heftklammer';

    private const string NEU = 'ein-ganz-anderer-langer-satz-2026';

    private const string HOST = 'belege.example.org';

    private ServerCrypto $crypto;

    private int $userId;

    private FakeMailTransport $mailTransport;

    private string $logFile;

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

        // The second factor has its own suite (TwoFactorFlowTest); here it
        // would only stand between the password and the pages under test.
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$this->userId]);

        $this->mailTransport = new FakeMailTransport(array_fill(0, 20, true));
        $this->mailSettings()->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein');

        $this->logFile = sys_get_temp_dir() . '/vb-password-flow-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ------------------------------------------------------------ change

    public function testDieAnmeldeseiteVerlinktPasswortVergessen(): void
    {
        $antwort = $this->dispatch($this->get('/anmelden'));

        self::assertStringContainsString('href="/anmelden/passwort-vergessen"', $antwort->body);
    }

    /**
     * "Passwort ändern (altes bekannt): U_priv neu wrappen, sonst nichts" -
     * same key pair, same grant, the vault opens with the new password only.
     */
    public function testPasswortAendernWrapptNeuUndBehaeltDieFreigabe(): void
    {
        $vault = $this->anmeldenUndTresor(self::PASSWORT);
        $schluessel = DataKey::generate();
        $versiegelt = $vault->sealDataKey($schluessel);
        $publicKeyVorher = new UserKeyRepository($this->pdo())->forUser($this->userId)?->publicKey();
        $grantVorher = $this->grantSealed();

        $antwort = $this->passwortAendern(self::PASSWORT, self::NEU, self::NEU);

        self::assertSame('/app/sicherheit', $antwort->headers['Location'] ?? null);
        self::assertSame($publicKeyVorher, new UserKeyRepository($this->pdo())->forUser($this->userId)?->publicKey());
        self::assertSame($grantVorher, $this->grantSealed(), 'Die Freigabe bleibt unverändert.');

        $_SESSION = [];
        self::assertNull(new Session()->userId());
        self::assertSame(200, $this->anmelden(self::PASSWORT)->status, 'Das alte Passwort gilt nicht mehr.');

        $neu = $this->anmeldenUndTresor(self::NEU);
        self::assertTrue($schluessel->equals($neu->openDataKey($versiegelt)));
    }

    public function testPasswortAendernSchicktEinenSicherheitshinweis(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);
        $this->passwortAendern(self::PASSWORT, self::NEU, self::NEU);

        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail);
        self::assertStringContainsString('Passwort wurde geändert', $mail->body);
        self::assertStringNotContainsString(self::NEU, $mail->body);
    }

    public function testEinFalschesAltesPasswortAendertNichts(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);
        $hashVorher = new UserRepository($this->pdo())->findById($this->userId)?->passwordHash;

        $antwort = $this->passwortAendern('das-ist-nicht-das-alte', self::NEU, self::NEU);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Das bisherige Passwort ist falsch.', $antwort->body);
        self::assertSame($hashVorher, new UserRepository($this->pdo())->findById($this->userId)?->passwordHash);
    }

    public function testFalscheAltePasswoerterWerdenBegrenzt(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);
        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_ACCOUNT; $i++) {
            $this->passwortAendern('falsch-falsch-falsch-' . $i, self::NEU, self::NEU);
        }

        $antwort = $this->passwortAendern(self::PASSWORT, self::NEU, self::NEU);

        self::assertStringContainsString('Zu viele Versuche', $antwort->body, 'Auch das richtige Passwort wartet.');
    }

    public function testDasNeuePasswortMussDieRegelnErfuellen(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);

        self::assertStringContainsString('stimmen nicht überein', $this->passwortAendern(self::PASSWORT, self::NEU, self::NEU . 'x')->body);
        self::assertStringContainsString('mindestens 12 Zeichen', $this->passwortAendern(self::PASSWORT, 'kurz', 'kurz')->body);
        self::assertStringContainsString('unterscheiden', $this->passwortAendern(self::PASSWORT, self::PASSWORT, self::PASSWORT)->body);
    }

    /**
     * Beyond the spec's "sonst nichts", by the user's decision in issue #18:
     * the other sessions of the account end, the one that changed stays.
     */
    public function testPasswortAendernBeendetAndereSitzungenAberNichtDieEigene(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);
        $andereSitzung = $_SESSION;

        $_SESSION = [];
        $this->anmeldenUndTresor(self::PASSWORT);
        $this->passwortAendern(self::PASSWORT, self::NEU, self::NEU);

        self::assertSame(200, $this->dispatch($this->get('/app'))->status, 'Die eigene Sitzung bleibt.');

        $_SESSION = $andereSitzung;
        $antwort = $this->dispatch($this->get('/app'));
        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', $antwort->headers['Location'] ?? '');
        self::assertNull(new Session()->userId(), 'Die andere Sitzung ist beendet.');
    }

    // ------------------------------------------------------------- reset

    public function testEineUnbekannteAdresseBekommtDieselbeAntwortUndKeineMail(): void
    {
        $bekannt = $this->resetAnfordern(self::EMAIL)->body;
        $mailsNachBekannt = count($this->mailTransport->gesendete);
        $unbekannt = $this->resetAnfordern('niemand@example.org')->body;

        self::assertSame(self::hinweisTeil($bekannt), self::hinweisTeil($unbekannt));
        self::assertCount($mailsNachBekannt, $this->mailTransport->gesendete, 'Keine Mail an eine unbekannte Adresse.');
        self::assertSame(1, $mailsNachBekannt);
    }

    public function testEinGesperrtesKontoBekommtKeinenLink(): void
    {
        $this->pdo()->prepare("UPDATE `user` SET status = 'gesperrt' WHERE id = ?")->execute([$this->userId]);

        $this->resetAnfordern(self::EMAIL);

        self::assertSame([], $this->mailTransport->gesendete);
    }

    public function testNurDerHashDesTokensStehtInDerDatenbank(): void
    {
        $token = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));

        $zeilen = $this->pdo()->query('SELECT * FROM auth_token')->fetchAll();
        self::assertCount(1, $zeilen);
        foreach ($zeilen[0] as $wert) {
            self::assertStringNotContainsString($token, (string) $wert);
            self::assertStringNotContainsString((string) hex2bin($token), (string) $wert);
        }
    }

    public function testDerLinkWirdErstNachDerFormularpruefungVerbraucht(): void
    {
        $token = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));

        $ohneHaken = $this->resetAbschliessen($token, self::NEU, self::NEU, verstanden: false);
        self::assertStringContainsString('verstanden', $ohneHaken->body);

        $zuKurz = $this->resetAbschliessen($token, 'kurz', 'kurz');
        self::assertStringContainsString('mindestens 12 Zeichen', $zuKurz->body);

        self::assertSame('/anmelden', $this->resetAbschliessen($token, self::NEU, self::NEU)->headers['Location'] ?? null);
    }

    /**
     * "Passwort vergessen: U_priv ist verloren → neues Schlüsselpaar, alte
     * Grant-Zeile gelöscht → erneute Freigabe durch einen Admin nötig" -
     * and the user is told so, on the way out of the form and again at the
     * next login.
     */
    public function testNachDemResetGibtEsEinNeuesSchluesselpaarUndKeineFreigabe(): void
    {
        $vault = $this->anmeldenUndTresor(self::PASSWORT);
        $publicKeyVorher = new UserKeyRepository($this->pdo())->forUser($this->userId)?->publicKey();
        $alterGrant = VaultGrant::fromStorage($this->grantSealed());
        $_SESSION = [];

        $token = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));
        $antwort = $this->resetAbschliessen($token, self::NEU, self::NEU);

        self::assertSame('/anmelden', $antwort->headers['Location'] ?? null);
        self::assertStringContainsString('Administrator', (string) ($_SESSION['flash']['text'] ?? ''));
        self::assertNotSame($publicKeyVorher, new UserKeyRepository($this->pdo())->forUser($this->userId)?->publicKey());
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($this->userId));

        // Old password: gone. New password: logged in - without a vault.
        self::assertSame(200, $this->anmelden(self::PASSWORT)->status);
        $login = $this->anmelden(self::NEU);
        self::assertSame('/app', $login->headers['Location'] ?? null);
        self::assertNull(self::vaultCookie($login), 'Ohne Freigabe kein Sitzungsschlüssel.');
        self::assertStringContainsString('Freigabe', (string) ($_SESSION['flash']['text'] ?? ''));

        // Even with the old grant row in hand, the new key pair cannot open
        // it - the reset really did cut the account off from the vault.
        $neuesPaar = new UserKeyRepository($this->pdo())->forUser($this->userId)?->unwrap(self::NEU);
        self::assertNotNull($neuesPaar);
        $this->expectException(CryptoException::class);
        $alterGrant->open($neuesPaar, $vault->publicKey());
    }

    public function testDerResetBeendetAlleSitzungen(): void
    {
        $this->anmeldenUndTresor(self::PASSWORT);
        $alteSitzung = $_SESSION;
        $_SESSION = [];

        $this->resetAbschliessen($this->tokenAusMail($this->resetAnfordern(self::EMAIL)), self::NEU, self::NEU);

        $_SESSION = $alteSitzung;
        $antwort = $this->dispatch($this->get('/app'));
        self::assertSame(302, $antwort->status);
        self::assertStringStartsWith('/anmelden', $antwort->headers['Location'] ?? '');
    }

    public function testNachDemResetGehtEineMailOhnePasswortRaus(): void
    {
        $this->resetAbschliessen($this->tokenAusMail($this->resetAnfordern(self::EMAIL)), self::NEU, self::NEU);

        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail);
        self::assertStringContainsString('zurückgesetzt', $mail->body);
        self::assertStringNotContainsString(self::NEU, $mail->body);
    }

    /** "Reset-Token (einmalig, Ablauf)" - Pflicht-Test of spec 01. */
    public function testDerLinkGiltNurEinmal(): void
    {
        $token = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));
        $this->resetAbschliessen($token, self::NEU, self::NEU);

        $zweites = $this->resetAbschliessen($token, 'noch-ein-anderer-satz-2027', 'noch-ein-anderer-satz-2027');

        self::assertStringContainsString('ungültig oder abgelaufen', $zweites->body);
        self::assertStringContainsString('ungültig oder abgelaufen', $this->dispatch($this->get('/anmelden/passwort-neu?token=' . $token))->body);
    }

    public function testDerLinkLaeuftNachDreissigMinutenAb(): void
    {
        $reset = $this->resetService();
        $jetzt = new \DateTimeImmutable('2026-09-22 10:00:00');
        $anfrage = $reset->request(self::EMAIL, '198.51.100.7', $jetzt);
        self::assertNotNull($anfrage->token);

        self::assertTrue($reset->isUsable($anfrage->token, $jetzt->modify('+29 minutes')));
        self::assertFalse($reset->isUsable($anfrage->token, $jetzt->modify('+30 minutes')));
        self::assertFalse($reset->complete($anfrage->token, self::NEU, self::NEU, $jetzt->modify('+31 minutes'))->istErfolg());
    }

    public function testEinNeuerLinkErsetztDenAlten(): void
    {
        $erster = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));
        $zweiter = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));

        self::assertNotSame($erster, $zweiter);
        self::assertFalse($this->resetService()->isUsable($erster));
        self::assertTrue($this->resetService()->isUsable($zweiter));
    }

    public function testResetAnfragenSindBegrenzt(): void
    {
        for ($i = 0; $i < PasswordReset::LIMIT_PER_ACCOUNT; $i++) {
            $this->resetAnfordern(self::EMAIL);
        }

        $antwort = $this->resetAnfordern(self::EMAIL);

        self::assertStringContainsString('Zu viele Anfragen', $antwort->body);
        self::assertCount(PasswordReset::LIMIT_PER_ACCOUNT, $this->mailTransport->gesendete);
    }

    /**
     * The whole lifecycle of spec 01's Pflicht-Test: after the reset an admin
     * seals the vault to the new public key - and the vault opens again.
     */
    public function testNachErneuterFreigabeOeffnetSichDerTresorWieder(): void
    {
        $vault = $this->anmeldenUndTresor(self::PASSWORT);
        $schluessel = DataKey::generate();
        $versiegelt = $vault->sealDataKey($schluessel);
        $_SESSION = [];

        $this->resetAbschliessen($this->tokenAusMail($this->resetAnfordern(self::EMAIL)), self::NEU, self::NEU);
        $_SESSION = [];

        // What the admin's "Freigeben" of M3-7 does: seal VK_priv to U_pub.
        $neuerPublicKey = (string) new UserKeyRepository($this->pdo())->forUser($this->userId)?->publicKey();
        new VaultGrantRepository($this->pdo())->insert($this->userId, VaultGrant::seal($vault, $neuerPublicKey));

        $wieder = $this->anmeldenUndTresor(self::NEU);
        self::assertTrue($schluessel->equals($wieder->openDataKey($versiegelt)));
    }

    // ------------------------------------------------- link base / host header

    public function testOhneEingestellteAdresseZaehltNurEinHostDerAbsenderDomain(): void
    {
        $this->resetAnfordern(self::EMAIL, host: 'angreifer.example.net');

        self::assertSame([], $this->mailTransport->gesendete, 'Kein Link auf einen fremden Host.');
        self::assertStringContainsString('no public URL configured', (string) file_get_contents($this->logFile));
        self::assertStringNotContainsString(self::EMAIL, (string) file_get_contents($this->logFile));
    }

    public function testDieEingestellteAdresseGewinntGegenDenHostHeader(): void
    {
        $this->mailSettings()->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein', 'https://belege.verein.example');

        $this->resetAnfordern(self::EMAIL, host: 'angreifer.example.net');

        self::assertStringContainsString('https://belege.verein.example/anmelden/passwort-neu?token=', $this->letzteMail());
    }

    public function testDieLinkseiteSchicktKeinenReferer(): void
    {
        $token = $this->tokenAusMail($this->resetAnfordern(self::EMAIL));

        $seite = $this->dispatch($this->get('/anmelden/passwort-neu?token=' . $token));

        self::assertSame(200, $seite->status);
        self::assertSame('no-referrer', $seite->headers['Referrer-Policy'] ?? null);
        self::assertStringContainsString('erneut freigibt', $seite->body, 'Die Folgen stehen vor dem Absenden da.');
    }

    public function testAbgelaufeneLinksRaeumtDerCronAuf(): void
    {
        $jetzt = new \DateTimeImmutable('2026-09-22 10:00:00');
        $this->resetService()->request(self::EMAIL, '198.51.100.7', $jetzt);

        self::assertSame(0, new AuthTokenRepository($this->pdo())->deleteExpired($jetzt->modify('+29 minutes')));
        self::assertSame(1, new AuthTokenRepository($this->pdo())->deleteExpired($jetzt->modify('+31 minutes')));
    }

    // ----------------------------------------------------------- scaffolding

    private function mailSettings(): MailSettingsRepository
    {
        return new MailSettingsRepository(new SettingRepository($this->pdo()), $this->crypto);
    }

    private function resetService(): PasswordReset
    {
        return new PasswordReset(
            $this->pdo(),
            $this->crypto,
            new PasswordHasher(),
            new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt'),
            new RateLimiter(new RateLimitRepository($this->pdo()), RateLimiter::LOGIN_WINDOW_SECONDS),
        );
    }

    private function grantSealed(): string
    {
        $grant = new VaultGrantRepository($this->pdo())->forUser($this->userId);
        self::assertNotNull($grant);

        return $grant->sealed();
    }

    private function anmelden(string $passwort): Response
    {
        $session = new Session();
        $session->start();

        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/anmelden',
            post: ['_csrf' => $session->csrfToken(), 'email' => self::EMAIL, 'passwort' => $passwort],
            ip: '198.51.100.7',
        ));
    }

    /**
     * Logs in and hands back the vault the session unlocked - proof that it
     * did, and the handle later assertions decrypt with.
     */
    private function anmeldenUndTresor(string $passwort): Vault
    {
        $antwort = $this->anmelden($passwort);
        self::assertSame('/app', $antwort->headers['Location'] ?? null, 'Anmeldung fehlgeschlagen.');

        $vault = new SessionVault()->unlock(self::vaultCookie($antwort));
        self::assertNotNull($vault, 'Der Tresor ist nicht entsperrt.');

        return $vault;
    }

    private function passwortAendern(string $alt, string $neu, string $wiederholung): Response
    {
        $session = new Session();
        $session->start();

        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/sicherheit/passwort',
            post: [
                '_csrf' => $session->csrfToken(),
                'passwort_alt' => $alt,
                'passwort' => $neu,
                'passwort_wiederholung' => $wiederholung,
            ],
            ip: '198.51.100.7',
        ));
    }

    private function resetAnfordern(string $email, string $host = self::HOST): Response
    {
        $session = new Session();
        $session->start();

        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/anmelden/passwort-vergessen',
            post: ['_csrf' => $session->csrfToken(), 'email' => $email],
            headers: ['host' => $host],
            ip: '198.51.100.7',
        ));
    }

    private function resetAbschliessen(string $token, string $neu, string $wiederholung, bool $verstanden = true): Response
    {
        $session = new Session();
        $session->start();

        $post = [
            '_csrf' => $session->csrfToken(),
            'token' => $token,
            'passwort' => $neu,
            'passwort_wiederholung' => $wiederholung,
        ];
        if ($verstanden) {
            $post['verstanden'] = '1';
        }

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden/passwort-neu', post: $post, ip: '198.51.100.7'));
    }

    private function letzteMail(): string
    {
        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail, 'Es wurde keine Mail versendet.');

        return $mail->body;
    }

    private function tokenAusMail(Response $antwort): string
    {
        self::assertSame(200, $antwort->status);
        $body = $this->letzteMail();
        self::assertStringContainsString('http://' . self::HOST . '/anmelden/passwort-neu?token=', $body);
        preg_match('/token=([0-9a-f]{64})/', $body, $treffer);
        self::assertArrayHasKey(1, $treffer);

        return $treffer[1];
    }

    /** The confirmation paragraph - the part that must not differ. */
    private static function hinweisTeil(string $body): string
    {
        preg_match('#<p class="hinweis hinweis-ok"[^>]*>(.*?)</p>#s', $body, $treffer);
        self::assertArrayHasKey(1, $treffer, 'Keine Bestätigung auf der Seite.');

        return $treffer[1];
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

    private function get(string $pfad): Request
    {
        $query = [];
        $teile = explode('?', $pfad, 2);
        if (isset($teile[1])) {
            parse_str($teile[1], $query);
        }

        return new Request(HttpMethod::Get, $teile[0], query: $query, ip: '198.51.100.7');
    }

    private function dispatch(Request $request): Response
    {
        $antwort = $this->kernel()->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * The real route table with the real guard and controllers.
     */
    private function kernel(): Kernel
    {
        $paths = new Paths(dirname(__DIR__, 2));
        $view = new View($paths->viewsDir(), '0.0.0-test');
        $pdo = $this->pdo();
        $crypto = $this->crypto;
        $policy = new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt');
        $limiter = new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS);

        $mfaService = new MfaService(
            new MfaTotpRepository($pdo),
            new MfaEmailCodeRepository($pdo),
            new MfaBackupCodeRepository($pdo),
            new TrustedDeviceRepository($pdo),
            $crypto,
            new RateLimiter(new RateLimitRepository($pdo), MfaService::WINDOW_SECONDS),
        );
        $mailer = new Mailer(
            new MailQueueRepository($pdo, $crypto),
            $this->mailSettings(),
            new MailTemplates($paths->viewsDir() . '/mail'),
            $this->mailTransport,
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
                $limiter,
                new PasswordHasher(),
            ),
            fn(): MfaService => $mfaService,
            fn(): AuditLog => $audit,
        );

        $sicherheit = fn(): SecurityController => new SecurityController(
            $view,
            new Session(),
            $mfaService,
            new MfaEnrollment(new MfaTotpRepository($pdo), new MfaBackupCodeRepository($pdo), new UserRepository($pdo), $crypto),
            new UserRepository($pdo),
            $mailer,
            $crypto,
            new PasswordChange($pdo, new PasswordHasher(), $policy, $limiter),
            $audit,
        );

        $passwort = fn(): PasswordController => new PasswordController(
            $view,
            new Session(),
            new SessionVault(),
            fn(): PasswordToolbox => new PasswordToolbox(
                new PasswordReset($pdo, $crypto, new PasswordHasher(), $policy, $limiter),
                new UserRepository($pdo),
                $mailer,
                $this->mailSettings(),
                $crypto,
                $audit,
                new FileLogger($this->logFile),
            ),
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

        $unerreichbar = static fn(): never => throw new \LogicException('Diese Route gehört nicht zu diesem Test.');

        $router = new Router();
        (require dirname(__DIR__, 2) . '/app/src/routes.php')(
            $router,
            $view,
            $auth,
            $guard,
            $unerreichbar,
            $sicherheit,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $passwort,
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
