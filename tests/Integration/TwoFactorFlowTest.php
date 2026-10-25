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
use App\Service\Account\Neuanmeldung;
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

    public function testDerLoginMitEMailMethodeVerschicktGenauEinenCodeOhneKnopf(): void
    {
        $this->emailMethodeEinrichten();

        $login = $this->passwortAnmelden();
        $pendingCookie = self::cookieValue($login, self::pendingCookieName());
        self::assertSame('/anmelden/bestaetigen', $login->headers['Location'] ?? null);
        self::assertNotNull($pendingCookie);

        self::assertSame(1, $this->anzahlQueueEintraege(), 'Genau ein Queue-Eintrag nach dem Login.');
        self::assertCount(1, $this->mailTransport->gesendete, 'Sofortiger Versandversuch.');
        self::assertStringStartsWith('Anmeldecode', $this->mailTransport->gesendete[0]->subject);

        // The code from that mail - without anyone pressing "Code erneut
        // senden" - finishes the login.
        $antwort = $this->bestaetigen($this->letzterVersendeterCode(), [self::pendingCookieName() => $pendingCookie]);

        self::assertSame('/app', $antwort->headers['Location'] ?? null);
        self::assertSame(1, $this->anzahlQueueEintraege());
    }

    public function testNeuladenDerBestaetigungsseiteVerschicktNichtErneut(): void
    {
        $this->emailMethodeEinrichten();
        $pendingCookie = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $cookies = [self::pendingCookieName() => $pendingCookie];

        for ($i = 0; $i < 3; $i++) {
            $seite = $this->dispatch(new Request(HttpMethod::Get, '/anmelden/bestaetigen', ip: '198.51.100.7', cookies: $cookies));
            self::assertSame(200, $seite->status);
        }

        self::assertSame(1, $this->anzahlQueueEintraege());
        self::assertCount(1, $this->mailTransport->gesendete);
    }

    public function testDerLoginMitTotpMethodeVerschicktKeinenCode(): void
    {
        $this->totpEinrichten();

        $login = $this->passwortAnmelden();

        self::assertSame('/anmelden/bestaetigen', $login->headers['Location'] ?? null);
        self::assertSame(0, $this->anzahlQueueEintraege());
        self::assertSame([], $this->mailTransport->gesendete);
    }

    public function testBeiGesperrtemZweitemFaktorGehtBeimLoginKeinCodeRaus(): void
    {
        $this->emailMethodeEinrichten();
        $mfa = new MfaService(
            new MfaTotpRepository($this->pdo()),
            new MfaEmailCodeRepository($this->pdo()),
            new MfaBackupCodeRepository($this->pdo()),
            new TrustedDeviceRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), MfaService::WINDOW_SECONDS),
        );
        for ($i = 0; $i < MfaService::LIMIT_PER_ACCOUNT; $i++) {
            $mfa->versuch('203.0.113.9', $this->userId);
        }
        self::assertTrue($mfa->isBlocked('198.51.100.7', $this->userId), 'Testvorbereitung: Konto ist gesperrt.');

        $login = $this->passwortAnmelden();

        self::assertSame('/anmelden/bestaetigen', $login->headers['Location'] ?? null);
        self::assertSame(0, $this->anzahlQueueEintraege());
    }

    public function testEinFehlgeschlagenerVersandBrichtDenLoginNichtAbUndWirdAngezeigt(): void
    {
        $this->emailMethodeEinrichten();
        $this->mailTransport = new FakeMailTransport([false]);

        $login = $this->passwortAnmelden();
        $pendingCookie = (string) self::cookieValue($login, self::pendingCookieName());
        self::assertSame('/anmelden/bestaetigen', $login->headers['Location'] ?? null);

        $seite = $this->dispatch(new Request(
            HttpMethod::Get,
            '/anmelden/bestaetigen',
            ip: '198.51.100.7',
            cookies: [self::pendingCookieName() => $pendingCookie],
        ));

        self::assertStringContainsString('konnte nicht per E-Mail versendet werden', $seite->body);
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

    /**
     * Security review (issue #78): two parallel logins with the same code
     * both found it unused and both passed. Only the request that actually
     * marks (or deletes) the code wins now.
     */
    public function testEinCodeGewinntNurEinmalAuchBeiGleichzeitigenAnfragen(): void
    {
        $this->totpEinrichtenMitCodes();
        $backup = new MfaBackupCodeRepository($this->pdo());
        $id = (int) $this->pdo()->query('SELECT id FROM mfa_backup_code WHERE used_at IS NULL LIMIT 1')->fetchColumn();
        self::assertTrue($backup->markUsed($id));
        self::assertFalse($backup->markUsed($id), 'the second request finds it already used');

        $email = new MfaEmailCodeRepository($this->pdo());
        $email->put($this->userId, str_repeat("\x01", 32), new \DateTimeImmutable('+10 minutes'));
        self::assertFalse($email->consume($this->userId, str_repeat("\x02", 32)), 'another code is not this one');
        self::assertTrue($email->consume($this->userId, str_repeat("\x01", 32)));
        self::assertFalse($email->consume($this->userId, str_repeat("\x01", 32)));
    }

    /**
     * Security review (issue #78, ASVS 2.8.4): a TOTP code is valid for
     * about 90 s, but it opens one login only - a second login with the same
     * code (seen over a shoulder, or replayed in parallel) is refused.
     */
    public function testEinTotpCodeOeffnetNurEineAnmeldung(): void
    {
        $secret = $this->totpEinrichten();
        $code = $this->totpCode($secret);

        $pending = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        self::assertSame('/app', $this->bestaetigen($code, [self::pendingCookieName() => $pending])->headers['Location'] ?? null);

        new Session()->destroy();
        new SessionVault()->clear();
        $_SESSION = [];
        $pending = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $zweiter = $this->bestaetigen($code, [self::pendingCookieName() => $pending]);

        self::assertNotSame('/app', $zweiter->headers['Location'] ?? null);
        self::assertNull(new Session()->userId(), 'the same code does not log in twice');
    }

    /** The code that confirmed the setup does not open a login afterwards (#78). */
    public function testDerEinrichtungscodeOeffnetKeineAnmeldung(): void
    {
        $jetzt = new \DateTimeImmutable();
        $enrollment = $this->mfaEnrollment();
        $secret = $enrollment->startTotp();
        $code = Totp::code($secret, $jetzt);
        self::assertNotNull($enrollment->confirmTotp($this->userId, $secret, $code, $jetzt));

        $mfa = new MfaService(
            new MfaTotpRepository($this->pdo()),
            new MfaEmailCodeRepository($this->pdo()),
            new MfaBackupCodeRepository($this->pdo()),
            new TrustedDeviceRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), MfaService::WINDOW_SECONDS),
        );
        self::assertFalse($mfa->verifyTotp($this->userId, $code, $jetzt), 'the setup spent this code');
        self::assertTrue($mfa->verifyTotp($this->userId, Totp::code($secret, $jetzt->modify('+30 seconds')), $jetzt), 'the next one works');
    }

    public function testEinNeuesTotpGeheimnisBeginntOhneVerbrauchtenSchritt(): void
    {
        $this->totpEinrichten();
        $repo = new MfaTotpRepository($this->pdo());
        // The setup already spent the step of its own code.
        $eingerichtet = (int) $this->pdo()->query('SELECT last_step FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn();
        self::assertGreaterThan(0, $eingerichtet);

        self::assertFalse($repo->claimStep($this->userId, $eingerichtet), 'the setup code is spent');
        self::assertTrue($repo->claimStep($this->userId, $eingerichtet + 1));
        self::assertFalse($repo->claimStep($this->userId, $eingerichtet + 1), 'the same step once only');
        self::assertFalse($repo->claimStep($this->userId, $eingerichtet), 'nor an older one');
        self::assertTrue($repo->claimStep($this->userId, $eingerichtet + 2));

        // A new secret starts from the step of the code that confirmed it.
        $repo->putConfirmed($this->userId, str_repeat("\x00", 61), 4);
        self::assertFalse($repo->claimStep($this->userId, 4), 'its confirming code is spent');
        self::assertTrue($repo->claimStep($this->userId, 5), 'a new secret starts afresh');
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

        // Not stored until confirmed (issue #246): the pending secret lives
        // in the session, as server-key ciphertext.
        self::assertFalse($this->pdo()->query('SELECT secret_enc FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn());
        $secretEnc = $session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN);
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

    // ------------------------------------- Neuanmeldung (N-08, issue #246)

    /**
     * Changing a factor that is in place - another method, a new
     * authenticator, new backup codes - first asks for password and the
     * current factor again; a live session is not enough.
     */
    public function testOhneNeuanmeldungFuehrtJedeAenderungZurBestaetigung(): void
    {
        $this->totpAngemeldet();
        $session = new Session();
        $csrf = $session->csrfToken();

        $seite = $this->dispatch($this->get('/app/sicherheit/einrichten'));
        self::assertSame(302, $seite->status);
        self::assertSame('/app/sicherheit/bestaetigen?weiter=%2Fapp%2Fsicherheit%2Feinrichten', $seite->headers['Location'] ?? null);

        foreach (['/app/sicherheit/einrichten/totp/starten', '/app/sicherheit/einrichten/totp/bestaetigen', '/app/sicherheit/einrichten/email/starten', '/app/sicherheit/einrichten/email/bestaetigen'] as $pfad) {
            $antwort = $this->dispatch(new Request(HttpMethod::Post, $pfad, post: ['_csrf' => $csrf, 'code' => '123456'], ip: '198.51.100.7'));
            self::assertSame(302, $antwort->status, $pfad);
            self::assertStringStartsWith('/app/sicherheit/bestaetigen', (string) ($antwort->headers['Location'] ?? ''), $pfad);
        }
        self::assertNull($session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN), 'no setup was started');
        self::assertSame(0, $this->anzahlQueueEintraege(), 'no setup mail went out');

        $vorher = $this->backupCodeHashes();
        $neu = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/backup-codes/neu', post: ['_csrf' => $csrf], ip: '198.51.100.7'));
        self::assertSame('/app/sicherheit/bestaetigen?weiter=%2Fapp%2Fsicherheit', $neu->headers['Location'] ?? null);
        self::assertSame($vorher, $this->backupCodeHashes(), 'the backup codes stay');

        $formular = $this->dispatch(new Request(HttpMethod::Get, '/app/sicherheit/bestaetigen', query: ['weiter' => '/app/sicherheit'], ip: '198.51.100.7'));
        self::assertSame(200, $formular->status);
        self::assertStringContainsString('name="weiter" value="/app/sicherheit"', $formular->body);
        self::assertStringContainsString('autocomplete="current-password"', $formular->body);
    }

    public function testDieNeuanmeldungVerlangtPasswortUndAktuellenFaktor(): void
    {
        $secret = $this->totpAngemeldet();

        $ohnePasswort = $this->neuanmelden('falsches Passwort', $this->naechsterCode($secret));
        self::assertSame(200, $ohnePasswort->status);
        self::assertStringContainsString('Passwort ist falsch', $ohnePasswort->body);
        self::assertFalse(new Session()->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN));

        $ohneCode = $this->neuanmelden(self::PASSWORT, '000000');
        self::assertStringContainsString('Code ist ungültig', $ohneCode->body);
        self::assertFalse(new Session()->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN));

        $ok = $this->neuanmelden(self::PASSWORT, $this->naechsterCode($secret));
        self::assertSame(302, $ok->status);
        self::assertSame('/app/sicherheit/einrichten', $ok->headers['Location'] ?? null);
        self::assertTrue(new Session()->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN));
        self::assertSame(200, $this->dispatch($this->get('/app/sicherheit/einrichten'))->status);
    }

    /** Only the account's own security pages are a target - no open redirect. */
    public function testDieNeuanmeldungLeitetNurAufDieSicherheitsseitenWeiter(): void
    {
        $secret = $this->totpAngemeldet();

        $antwort = $this->neuanmelden(self::PASSWORT, $this->naechsterCode($secret), weiter: 'https://example.org/');

        self::assertSame('/app/sicherheit/einrichten', $antwort->headers['Location'] ?? null);
    }

    public function testEinBackupCodeGenuegtFuerDieNeuanmeldung(): void
    {
        $codes = $this->totpEinrichtenMitCodes();
        $this->totpAngemeldet(eingerichtet: true);

        $antwort = $this->neuanmelden(self::PASSWORT, $codes[3], weiter: '/app/sicherheit');

        self::assertSame('/app/sicherheit', $antwort->headers['Location'] ?? null);
        $neu = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/backup-codes/neu', post: ['_csrf' => new Session()->csrfToken()], ip: '198.51.100.7'));
        self::assertSame(200, $neu->status);
        self::assertSame(10, preg_match_all('/[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}/', $neu->body));
        self::assertFalse(new Session()->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN), 'the change spends the confirmation');
    }

    /**
     * Security review N-08: a setup that is started and then abandoned (a
     * closed tab, a QR code nobody scanned) leaves the authenticator that
     * is in place working - before, starting already overwrote it.
     */
    public function testEineAbgebrocheneNeueinrichtungEntwertetDenBestehendenTotpNicht(): void
    {
        $secret = $this->totpAngemeldet();
        $this->neuanmelden(self::PASSWORT, $this->naechsterCode($secret));
        $session = new Session();

        $gestartet = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/starten', post: ['_csrf' => $session->csrfToken()], ip: '198.51.100.7'));
        self::assertSame(200, $gestartet->status);
        self::assertStringContainsString('<svg', $gestartet->body);
        self::assertNotNull($session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN));

        // Abandoned here. The old secret is still the account's.
        $gespeichert = $this->pdo()->query('SELECT secret_enc FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn();
        self::assertIsString($gespeichert);
        self::assertSame($secret, $this->crypto->decrypt($gespeichert));
        $mfa = $this->mfaService();
        self::assertTrue($mfa->verifyTotp($this->userId, Totp::code($secret, new \DateTimeImmutable('+60 seconds')), new \DateTimeImmutable('+60 seconds')));
    }

    public function testEineBestaetigteNeueinrichtungErsetztDasGeheimnisUndVerbrauchtDieBestaetigung(): void
    {
        $alt = $this->totpAngemeldet();
        $this->neuanmelden(self::PASSWORT, $this->naechsterCode($alt));
        $session = new Session();
        $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/starten', post: ['_csrf' => $session->csrfToken()], ip: '198.51.100.7'));
        $neu = $this->crypto->decrypt((string) $session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN));

        $falsch = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/bestaetigen', post: ['_csrf' => $session->csrfToken(), 'code' => '000000'], ip: '198.51.100.7'));
        self::assertStringContainsString('stimmt nicht', $falsch->body);
        self::assertStringContainsString('<svg', $falsch->body, 'the QR code is shown again');

        $richtig = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/bestaetigen', post: ['_csrf' => $session->csrfToken(), 'code' => Totp::code($neu, new \DateTimeImmutable())], ip: '198.51.100.7'));
        self::assertStringContainsString('eingerichtet', $richtig->body);

        $gespeichert = (string) $this->pdo()->query('SELECT secret_enc FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn();
        self::assertSame($neu, $this->crypto->decrypt($gespeichert));
        self::assertNull($session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN));
        self::assertFalse($session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN), 'a second change asks again');
        self::assertSame(302, $this->dispatch($this->get('/app/sicherheit/einrichten'))->status);
    }

    /**
     * The re-authentication running out while the QR code is being scanned
     * does not cost the setup: it has its own window from the start.
     */
    public function testEineGestarteteEinrichtungUeberlebtDasEndeDerBestaetigung(): void
    {
        $alt = $this->totpAngemeldet();
        $this->neuanmelden(self::PASSWORT, $this->naechsterCode($alt));
        $session = new Session();
        $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/starten', post: ['_csrf' => $session->csrfToken()], ip: '198.51.100.7'));
        $neu = $this->crypto->decrypt((string) $session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN));
        $session->verwirfNeuanmeldung();

        $richtig = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/bestaetigen', post: ['_csrf' => $session->csrfToken(), 'code' => Totp::code($neu, new \DateTimeImmutable())], ip: '198.51.100.7'));

        self::assertSame(200, $richtig->status);
        self::assertStringContainsString('eingerichtet', $richtig->body);

        // A pending secret older than the window is not: back to the confirmation.
        $session->merkeTotpEinrichtung($this->crypto->encrypt($neu), new \DateTimeImmutable('-11 minutes'));
        $spaet = $this->dispatch(new Request(HttpMethod::Post, '/app/sicherheit/einrichten/totp/bestaetigen', post: ['_csrf' => $session->csrfToken(), 'code' => '123456'], ip: '198.51.100.7'));
        self::assertStringStartsWith('/app/sicherheit/bestaetigen', (string) ($spaet->headers['Location'] ?? ''));
    }

    /** Switching to e-mail drops the authenticator: the login would otherwise still take its codes. */
    public function testDerWechselAufEMailEntferntDasTotpGeheimnis(): void
    {
        $this->totpEinrichten();

        $this->emailMethodeEinrichten();

        self::assertFalse($this->pdo()->query('SELECT 1 FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn());
    }

    public function testDieBestaetigungGiltZehnMinuten(): void
    {
        $_SESSION = [];
        $session = new Session();
        $jetzt = new \DateTimeImmutable('2026-03-01 10:00:00');
        self::assertFalse($session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN, $jetzt));

        $session->bestaetigeNeuanmeldung($jetzt);

        self::assertTrue($session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN, $jetzt->modify('+9 minutes')));
        self::assertFalse($session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN, $jetzt->modify('+10 minutes')));
        $session->login($this->userId, $jetzt);
        self::assertFalse($session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN, $jetzt), 'a new login starts without one');
    }

    /** The first setup has no factor to ask for - it goes straight through. */
    public function testDieErsteEinrichtungBrauchtKeineNeuanmeldung(): void
    {
        $this->passwortAnmelden();

        self::assertSame(200, $this->dispatch($this->get('/app/sicherheit/einrichten'))->status);
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

    /**
     * A finished TOTP login: the secret is set up (unless it already is),
     * password and current code are through, the session is live.
     */
    private function totpAngemeldet(bool $eingerichtet = false): string
    {
        $secret = $eingerichtet
            ? $this->crypto->decrypt((string) $this->pdo()->query('SELECT secret_enc FROM mfa_totp WHERE user_id = ' . $this->userId)->fetchColumn())
            : $this->totpEinrichten();
        $pending = (string) self::cookieValue($this->passwortAnmelden(), self::pendingCookieName());
        $antwort = $this->bestaetigen($this->totpCode($secret), [self::pendingCookieName() => $pending]);
        self::assertSame('/app', $antwort->headers['Location'] ?? null, 'Testvorbereitung: Anmeldung mit TOTP.');

        return $secret;
    }

    /** The login spent the current step; the next one (inside ±1) is still unused. */
    private function naechsterCode(string $secret): string
    {
        return Totp::code($secret, new \DateTimeImmutable('+' . Totp::DEFAULT_PERIOD . ' seconds'));
    }

    private function neuanmelden(string $passwort, string $code, string $weiter = '/app/sicherheit/einrichten'): Response
    {
        $session = new Session();
        $session->start();

        return $this->dispatch(new Request(
            HttpMethod::Post,
            '/app/sicherheit/bestaetigen',
            post: ['_csrf' => $session->csrfToken(), 'passwort' => $passwort, 'code' => $code, 'weiter' => $weiter],
            ip: '198.51.100.7',
        ));
    }

    /** @return list<string> */
    private function backupCodeHashes(): array
    {
        return array_map('bin2hex', $this->pdo()->query('SELECT code_hash FROM mfa_backup_code WHERE user_id = ' . $this->userId . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function mfaService(): MfaService
    {
        return new MfaService(
            new MfaTotpRepository($this->pdo()),
            new MfaEmailCodeRepository($this->pdo()),
            new MfaBackupCodeRepository($this->pdo()),
            new TrustedDeviceRepository($this->pdo()),
            $this->crypto,
            new RateLimiter(new RateLimitRepository($this->pdo()), MfaService::WINDOW_SECONDS),
        );
    }

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
        $secret = $enrollment->startTotp();
        $now ??= new \DateTimeImmutable();
        // The code of the step BEFORE now (inside the ±1 window): the setup
        // spends its step (issue #78), and the tests log in with the
        // current code right after.
        $codes = $enrollment->confirmTotp($this->userId, $secret, self::vorigerCode($secret, $now), $now);
        self::assertNotNull($codes, 'Testvorbereitung: TOTP-Einrichtung muss gelingen.');

        return $secret;
    }

    /**
     * @return list<string>
     */
    private function totpEinrichtenMitCodes(): array
    {
        $enrollment = $this->mfaEnrollment();
        $secret = $enrollment->startTotp();
        $codes = $enrollment->confirmTotp($this->userId, $secret, self::vorigerCode($secret, new \DateTimeImmutable()));
        self::assertNotNull($codes);

        return $codes;
    }

    private static function vorigerCode(string $secret, \DateTimeImmutable $now): string
    {
        return Totp::code($secret, $now->modify('-' . Totp::DEFAULT_PERIOD . ' seconds'));
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

    private function anzahlQueueEintraege(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
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

        $mfaToolbox = fn(): MfaToolbox => new MfaToolbox($mfaServiceFor($pdo), new UserRepository($pdo), $mailerFor($pdo), new SettingRepository($pdo), $crypto, $audit);

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
            $mfaToolbox,
            fn(): AuditLog => $audit,
        );

        $mfaController = fn(): MfaController => new MfaController(
            $view,
            new Session(),
            new PendingLogin(),
            new LoginCompleter(new Session(), new SessionVault()),
            $mfaToolbox,
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
            new Neuanmeldung(
                new UserRepository($pdo),
                new PasswordHasher(),
                $mfaServiceFor($pdo),
                new RateLimiter(new RateLimitRepository($pdo), RateLimiter::LOGIN_WINDOW_SECONDS),
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
