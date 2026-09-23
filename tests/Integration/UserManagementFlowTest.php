<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Admin\UserController;
use App\Admin\VaultGrantController;
use App\App\AuthController;
use App\App\InvitationController;
use App\App\LoginCompleter;
use App\App\PasswordController;
use App\App\PasswordToolbox;
use App\App\SecurityController;
use App\Config\Paths;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\SystemRole;
use App\Domain\UserStatus;
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
use App\Repository\CostCenterRepository;
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
use App\Service\Account\UserAdministration;
use App\Service\Account\UserRuleViolation;
use App\Service\Audit\AuditChain;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
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
 * User management, invitation and vault grants end to end (issue #20/M3-7,
 * docs/spec/01-sicherheit.md sections 2 and 4), through the real route
 * table, guard and controllers - the technique of PasswordFlowTest:
 * $_SESSION is the session file, each actor's session is kept aside while
 * another one acts, and the vault cookie of a login response travels into
 * that actor's later requests.
 *
 * The Pflicht-Test of spec 01 this covers: the user lifecycle "Einladung →
 * Freigabe → Passwort ändern → Reset → erneute Freigabe" including "ohne
 * Grant kein Entschlüsseln".
 */
final class UserManagementFlowTest extends DatabaseTestCase
{
    private const string ADMIN = 'vorsitz@example.org';

    private const string ADMIN_PW = 'korrekt-pferd-batterie-heftklammer';

    private const string NEU = 'kasse@example.org';

    private const string NEU_PW = 'ein-ganz-anderer-langer-satz-2026';

    private const string HOST = 'belege.example.org';

    private ServerCrypto $crypto;

    private int $adminId;

    private FakeMailTransport $mailTransport;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        /** @var array{server_key: string} $config */
        $config = self::configData();
        $this->crypto = new ServerCrypto((string) base64_decode($config['server_key'], true));
        $this->adminId = new FirstAdminSetup($this->pdo())->create(self::ADMIN, 'Anna Admin', self::ADMIN_PW, $config['server_key'])['userId'];
        // The second factor has its own suite; here it would only stand
        // between the password and the pages under test.
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$this->adminId]);

        $this->mailTransport = new FakeMailTransport(array_fill(0, 40, true));
        $this->mailSettings()->save('smtp', 'smtp.example.org', 587, SmtpSecurity::Starttls, '', null, 'verein@example.org', '', 'Verein');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ------------------------------------------------------------ invite

    public function testEinladenSchicktEinenLinkUndSpeichertNurDessenHash(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer', [
            'name' => 'Karl Kasse',
            'email' => self::NEU,
            'rolle' => [(string) $this->rolle(SystemRole::Finanzen)],
        ]));

        $id = $this->idVon(self::NEU);
        self::assertSame('/admin/benutzer/' . $id, $antwort->headers['Location'] ?? null);
        self::assertSame(UserStatus::Eingeladen, new UserRepository($this->pdo())->findById($id)?->status);
        self::assertNull(new UserKeyRepository($this->pdo())->forUser($id), 'Der Schlüssel entsteht erst mit dem Passwort.');

        $token = $this->tokenAusMail();
        $mail = $this->letzteMail();
        self::assertStringContainsString('72 Stunden', $mail);
        self::assertStringNotContainsString('Karl', $mail, 'Keine Namen in der Mail.');

        $zeile = $this->pdo()->query("SELECT token_hash, expires_at, created_at FROM auth_token WHERE typ = 'invite'")->fetch();
        self::assertIsArray($zeile);
        self::assertNotSame($token, $zeile['token_hash']);
        self::assertNotSame(hex2bin($token), $zeile['token_hash']);
        self::assertSame(
            72 * 3600,
            new \DateTimeImmutable((string) $zeile['expires_at'])->getTimestamp() - new \DateTimeImmutable((string) $zeile['created_at'])->getTimestamp(),
        );
    }

    public function testEineVergebeneAdresseWirdAbgelehnt(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer', [
            'name' => 'Doppelt',
            'email' => self::ADMIN,
            'rolle' => [(string) $this->rolle(SystemRole::Vorstand)],
        ]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('bereits einen Benutzer', $antwort->body);
        self::assertSame(1, new UserRepository($this->pdo())->count());
        self::assertSame([], $this->mailTransport->gesendete);
    }

    public function testEineAbgelehnteRollenkombinationLegtNiemandenAn(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer', [
            'name' => 'Gemischt',
            'email' => self::NEU,
            'rolle' => [(string) $this->rolle(SystemRole::Kassenpruefer), (string) $this->rolle(SystemRole::Finanzen)],
        ]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('nicht mit internen Rollen kombinieren', $antwort->body);
        self::assertSame(1, new UserRepository($this->pdo())->count(), 'Die halb angelegte Zeile ist wieder weg.');
    }

    public function testSchreibendeAufrufeBrauchenDasCsrfToken(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $_SESSION = $admin['session'];

        $antwort = $this->dispatch(new Request(HttpMethod::Post, '/admin/benutzer', post: [
            'name' => 'Ohne Token',
            'email' => self::NEU,
            'rolle' => [(string) $this->rolle(SystemRole::Vorstand)],
        ], headers: ['host' => self::HOST]));

        self::assertSame(302, $antwort->status);
        self::assertSame(1, new UserRepository($this->pdo())->count());
    }

    public function testDieEinladungAnnehmenAktiviertDasKontoOhneFreigabe(): void
    {
        $id = $this->einladen(self::NEU, SystemRole::Finanzen);
        $token = $this->tokenAusMail();

        $seite = $this->dispatch($this->get('/anmelden/einladung?token=' . $token));
        self::assertSame(200, $seite->status);
        self::assertSame('no-referrer', $seite->headers['Referrer-Policy'] ?? null);
        self::assertStringContainsString('für den Tresor freigegeben', $seite->body, 'Die Folge steht vor dem Absenden da.');

        $antwort = $this->annehmen($token, self::NEU_PW);
        self::assertSame('/anmelden', $antwort->headers['Location'] ?? null);

        $konto = new UserRepository($this->pdo())->findById($id);
        self::assertSame(UserStatus::Aktiv, $konto?->status);
        self::assertNotNull(new UserKeyRepository($this->pdo())->forUser($id));
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($id));
        self::assertSame([$id], $this->verwaltung()->ausstehend());

        // "Admins bekommen dazu eine Mail" - queued, the cron sends it.
        $wartend = $this->pdo()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'offen'")->fetchColumn();
        self::assertSame(1, (int) $wartend, 'Ein Hinweis an die eine Admin.');

        // Login works - without a vault.
        $login = $this->anmelden(self::NEU, self::NEU_PW);
        self::assertNull($login['vault']);
    }

    public function testDerEinladungslinkGiltNurEinmal(): void
    {
        $this->einladen(self::NEU, SystemRole::Vorstand);
        $token = $this->tokenAusMail();
        $this->annehmen($token, self::NEU_PW);

        $zweites = $this->annehmen($token, 'noch-ein-anderer-satz-2027');

        self::assertStringContainsString('ungültig oder abgelaufen', $zweites->body);
        self::assertStringContainsString('ungültig oder abgelaufen', $this->dispatch($this->get('/anmelden/einladung?token=' . $token))->body);
    }

    public function testEinZuSchwachesPasswortVerbrauchtDenLinkNicht(): void
    {
        $this->einladen(self::NEU, SystemRole::Vorstand);
        $token = $this->tokenAusMail();

        $antwort = $this->annehmen($token, 'kurz');

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('12 Zeichen', $antwort->body);
        self::assertTrue($this->einladung()->isUsable($token));
    }

    public function testEineNeueEinladungErsetztDieAlte(): void
    {
        $id = $this->einladen(self::NEU, SystemRole::Vorstand);
        $erster = $this->tokenAusMail();

        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id . '/einladung-erneut', []));
        $zweiter = $this->tokenAusMail();

        self::assertNotSame($erster, $zweiter);
        self::assertFalse($this->einladung()->isUsable($erster));
        self::assertTrue($this->einladung()->isUsable($zweiter));
    }

    public function testDieEinladungLaeuftNach72StundenAb(): void
    {
        $jetzt = new \DateTimeImmutable('2026-09-22 10:00:00');
        $einladung = $this->einladung();
        $ergebnis = $einladung->invite(self::NEU, 'Karl Kasse', [$this->rolle(SystemRole::Vorstand)], now: $jetzt);

        self::assertTrue($einladung->isUsable($ergebnis['token'], $jetzt->modify('+71 hours')));
        self::assertFalse($einladung->isUsable($ergebnis['token'], $jetzt->modify('+72 hours')));
        self::assertFalse($einladung->complete($ergebnis['token'], self::NEU_PW, self::NEU_PW, $jetzt->modify('+73 hours'))->istErfolg());
    }

    public function testEineGesperrteEinladungLaesstSichNichtAnnehmen(): void
    {
        $id = $this->einladen(self::NEU, SystemRole::Vorstand);
        $token = $this->tokenAusMail();
        $this->verwaltung()->sperren($id, $this->adminId);

        self::assertFalse($this->einladung()->isUsable($token));

        // Unlocking an unanswered invitation brings it back to "eingeladen".
        $this->verwaltung()->entsperren($id);
        self::assertSame(UserStatus::Eingeladen, new UserRepository($this->pdo())->findById($id)?->status);
    }

    // ------------------------------------------------------ grant / banner

    public function testDasBannerZeigtAusstehendeFreigabenNurDenFreigebenden(): void
    {
        $vorstand = $this->aktiverBenutzer('vorstand@example.org', SystemRole::Vorstand);
        $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $liste = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/benutzer')));
        self::assertStringContainsString('2 Freigaben ausstehend', $liste->body);
        self::assertStringContainsString('href="/admin/tresor"', $liste->body);

        $tresor = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/tresor', $admin['vault'])));
        self::assertStringNotContainsString('Freigaben ausstehend:', $tresor->body, 'Nicht auf der Seite, die sie ohnehin listet.');
        self::assertStringContainsString('/admin/tresor/' . $vorstand . '/freigeben', $tresor->body);

        // Vorstand may not grant - no banner there.
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$vorstand]);
        $alsVorstand = $this->anmelden('vorstand@example.org', self::NEU_PW);
        $_SESSION = $alsVorstand['session'];
        self::assertStringNotContainsString('ausstehend', $this->dispatch($this->get('/app'))->body);
    }

    /**
     * The Pflicht-Test of spec 01: Einladung → Freigabe → Passwort ändern →
     * Reset → erneute Freigabe, with "ohne Grant kein Entschlüsseln" at
     * every step where there is no grant.
     */
    public function testDerGanzeLebenszyklus(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        self::assertNotNull($admin['vault']);
        $adminVault = $this->tresorVon($admin);
        $schluessel = DataKey::generate();
        $versiegelt = $adminVault->sealDataKey($schluessel);

        // Einladung.
        $id = $this->einladen(self::NEU, SystemRole::Finanzen);
        $this->annehmen($this->tokenAusMail(), self::NEU_PW);
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$id]);
        self::assertNull($this->anmelden(self::NEU, self::NEU_PW)['vault'], 'Ohne Freigabe kein Tresor.');

        // Freigabe.
        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/freigeben', [], $admin['vault']));
        self::assertSame('/admin/tresor', $antwort->headers['Location'] ?? null);
        self::assertStringContainsString('für den Tresor freigegeben', $this->letzteMail());
        self::assertSame([], $this->verwaltung()->ausstehend());
        $grant = $this->pdo()->query('SELECT granted_by FROM vault_grant WHERE user_id = ' . $id)->fetchColumn();
        self::assertSame($this->adminId, (int) $grant);

        $kasse = $this->anmelden(self::NEU, self::NEU_PW);
        self::assertTrue($schluessel->equals($this->tresorVon($kasse)->openDataKey($versiegelt)));

        // Passwort ändern: the grant stays.
        $_SESSION = $kasse['session'];
        $this->post('/app/sicherheit/passwort', [
            'passwort_alt' => self::NEU_PW,
            'passwort' => 'zweites-langes-passwort-fuer-karl',
            'passwort_wiederholung' => 'zweites-langes-passwort-fuer-karl',
        ]);
        $kasse = $this->anmelden(self::NEU, 'zweites-langes-passwort-fuer-karl');
        self::assertTrue($schluessel->equals($this->tresorVon($kasse)->openDataKey($versiegelt)));

        // Reset: new key pair, grant gone, the admins are told.
        $_SESSION = [];
        $reset = $this->resetService()->request(self::NEU, '198.51.100.7');
        self::assertNotNull($reset->token);
        $this->pdo()->exec('DELETE FROM mail_queue');
        $session = new Session();
        $session->start();
        $this->dispatch(new Request(HttpMethod::Post, '/anmelden/passwort-neu', post: [
            '_csrf' => $session->csrfToken(),
            'token' => $reset->token,
            'passwort' => 'drittes-langes-passwort-fuer-karl',
            'passwort_wiederholung' => 'drittes-langes-passwort-fuer-karl',
            'verstanden' => '1',
        ], headers: ['host' => self::HOST], ip: '198.51.100.7'));
        self::assertSame([$id], $this->verwaltung()->ausstehend());
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'offen'")->fetchColumn());
        self::assertNull($this->anmelden(self::NEU, 'drittes-langes-passwort-fuer-karl')['vault']);

        // Erneute Freigabe.
        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/freigeben', [], $admin['vault']));
        $kasse = $this->anmelden(self::NEU, 'drittes-langes-passwort-fuer-karl');
        self::assertTrue($schluessel->equals($this->tresorVon($kasse)->openDataKey($versiegelt)));

        // Every step is in the audit log, chained (issue #21/M3-8) - the
        // logins in between left out here, LoginFlowTest covers them.
        $zeilen = array_reverse(new AuditLogRepository($this->pdo())->page(new AuditFilter(), null, 100));
        $schritte = array_values(array_filter(
            array_map(static fn(AuditEntry $e): array => [$e->action, $e->userId, $e->entityId], $zeilen),
            static fn(array $s): bool => !in_array($s[0], [AuditAction::LoginErfolg->value, AuditAction::Logout->value], true),
        ));
        self::assertSame([
            [AuditAction::BenutzerEingeladen->value, $this->adminId, $id],
            [AuditAction::EinladungAngenommen->value, $id, $id],
            [AuditAction::TresorFreigegeben->value, $this->adminId, $id],
            [AuditAction::PasswortGeaendert->value, $id, $id],
            [AuditAction::PasswortResetAbgeschlossen->value, $id, $id],
            [AuditAction::TresorFreigegeben->value, $this->adminId, $id],
        ], $schritte);
        self::assertTrue(AuditChain::verify($zeilen)->intakt());
        self::assertStringNotContainsString(self::NEU, implode('', array_map(static fn(AuditEntry $e): string => (string) $e->detailsEnc, $zeilen)));
    }

    public function testOhneEntsperrtenTresorGibtEsKeineFreigabe(): void
    {
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        // The session without its cookie: logged in, but no vault.
        $seite = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/tresor')));
        self::assertStringContainsString('nicht entsperrt', $seite->body);
        self::assertStringNotContainsString('/freigeben', $seite->body);

        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/freigeben', []));
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($id));
        self::assertStringContainsString('nicht entsperrt', (string) ($_SESSION['flash']['text'] ?? ''));
    }

    public function testEntziehenBeendetDieSitzungenDesKontos(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/freigeben', [], $admin['vault']));
        $kasse = $this->anmelden(self::NEU, self::NEU_PW);
        self::assertNotNull($kasse['vault']);

        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/entziehen', []));

        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($id));
        self::assertStringContainsString('entzogen', $this->letzteMail());
        $_SESSION = $kasse['session'];
        self::assertStringStartsWith('/anmelden', $this->dispatch($this->get('/app'))->headers['Location'] ?? '');
        self::assertNull($this->anmelden(self::NEU, self::NEU_PW)['vault']);
    }

    public function testDieEigeneFreigabeLaesstSichNichtEntziehen(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $this->adminId . '/entziehen', []));

        self::assertNotNull(new VaultGrantRepository($this->pdo())->forUser($this->adminId));
    }

    // ------------------------------------------------------ lock / unlock

    public function testSperrenNimmtDieFreigabeUndBeendetSitzungen(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $this->alsAdmin($admin, fn() => $this->post('/admin/tresor/' . $id . '/freigeben', [], $admin['vault']));
        $kasse = $this->anmelden(self::NEU, self::NEU_PW);

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id . '/sperren', []));

        self::assertSame(UserStatus::Gesperrt, new UserRepository($this->pdo())->findById($id)?->status);
        self::assertNull(new VaultGrantRepository($this->pdo())->forUser($id));
        $_SESSION = $kasse['session'];
        self::assertStringStartsWith('/anmelden', $this->dispatch($this->get('/app'))->headers['Location'] ?? '');
        self::assertNull($this->anmelden(self::NEU, self::NEU_PW)['session']['user_id'] ?? null, 'Gesperrt meldet sich nicht an.');

        // Unlock: active again, but waiting for a grant.
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id . '/entsperren', []));
        self::assertSame(UserStatus::Aktiv, new UserRepository($this->pdo())->findById($id)?->status);
        self::assertSame([$id], $this->verwaltung()->ausstehend());
        self::assertNull($this->anmelden(self::NEU, self::NEU_PW)['vault']);
    }

    public function testSperrenUndEntsperrenStehenImAuditLog(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->einladen(self::NEU, SystemRole::Finanzen);

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id . '/sperren', []));
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id . '/entsperren', []));

        $zeilen = new AuditLogRepository($this->pdo())->page(new AuditFilter(entity: 'user', entityId: $id), null, 10);
        self::assertSame(
            [AuditAction::BenutzerEntsperrt->value, AuditAction::BenutzerGesperrt->value, AuditAction::BenutzerEingeladen->value],
            array_map(static fn(AuditEntry $e): string => $e->action, $zeilen),
        );
        self::assertSame($this->adminId, $zeilen[0]->userId);
    }

    public function testDasEigeneKontoLaesstSichNichtSperren(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $this->adminId . '/sperren', []));

        self::assertSame(UserStatus::Aktiv, new UserRepository($this->pdo())->findById($this->adminId)?->status);
        self::assertStringContainsString('eigene Konto', (string) ($_SESSION['flash']['text'] ?? ''));
    }

    public function testDerLetzteVerwalterBleibt(): void
    {
        // Somebody who is not a manager tries (the service is the gate, the
        // route merely calls it): the only admin stays.
        $anderer = $this->aktiverBenutzer(self::NEU, SystemRole::Vorstand);

        $this->expectException(UserRuleViolation::class);
        $this->verwaltung()->sperren($this->adminId, $anderer);
    }

    // ------------------------------------------------------------ editing

    public function testEinInternesKontoKannEinAblaufdatumBekommen(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $finanzen = (string) $this->rolle(SystemRole::Finanzen);

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, ['name' => 'Karl K.', 'rolle' => [$finanzen], 'ablauf' => '2030-06-30']));

        $konto = new UserRepository($this->pdo())->findById($id);
        self::assertSame('2030-06-30 23:59:59', $konto?->expiresAt?->format('Y-m-d H:i:s'), 'Der ganze letzte Tag zählt.');
        self::assertSame('Karl K.', $this->crypto->decrypt((string) $konto?->displayNameEnc));

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, ['name' => 'Karl K.', 'rolle' => [$finanzen], 'ablauf' => '']));
        self::assertNull(new UserRepository($this->pdo())->findById($id)?->expiresAt);

        $seite = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/benutzer/' . $id)));
        self::assertStringContainsString('Karl K.', $seite->body);
    }

    public function testEinExternesKontoBekommtOhneAngabe60Tage(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);

        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, [
            'name' => 'Petra Prüfer',
            'rolle' => [(string) $this->rolle(SystemRole::Kassenpruefer)],
            'ablauf' => '',
        ]));

        $konto = new UserRepository($this->pdo())->findById($id);
        self::assertNotNull($konto?->expiresAt);
        self::assertEqualsWithDelta(
            new \DateTimeImmutable('+60 days')->getTimestamp(),
            $konto->expiresAt->getTimestamp(),
            120,
        );
        self::assertTrue($konto->mfaRequired);
    }

    public function testEinUngueltigesDatumBehaeltDasFormular(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);

        $antwort = $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, [
            'name' => 'Karl',
            'rolle' => [(string) $this->rolle(SystemRole::Finanzen)],
            'ablauf' => '2030-02-31',
        ]));

        self::assertSame(422, $antwort->status);
        self::assertStringContainsString('gültiges Datum', $antwort->body);
    }

    public function testDieListeZeigtStatusUndTresor(): void
    {
        $this->aktiverBenutzer(self::NEU, SystemRole::Finanzen);
        $this->einladen('neu@example.org', SystemRole::Vorstand);
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        $liste = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/benutzer')));

        self::assertSame(200, $liste->status);
        self::assertStringContainsString(self::NEU, $liste->body);
        self::assertStringContainsString('Eingeladen', $liste->body);
        self::assertStringContainsString('Freigabe ausstehend', $liste->body);
        self::assertStringContainsString('Freigegeben', $liste->body);
        self::assertStringContainsString('href="/admin/benutzer"', $liste->body, 'Der Navigationseintrag ist live.');
    }

    public function testEinUnbekannterBenutzerIst404(): void
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);

        self::assertSame(404, $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/benutzer/999999')))->status);
    }

    /**
     * Deactivating a cost center (M4-1, issue #23) must not silently drop an
     * already-assigned account's scope: the form still shows and keeps it,
     * marked "(inaktiv)".
     */
    public function testEineDeaktivierteZugewieseneKostenstelleBleibtImFormular(): void
    {
        $kostenstellen = new CostCenterRepository($this->pdo());
        $jugendId = $kostenstellen->create('Jugend');

        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $id = $this->einladen(self::NEU, SystemRole::Vereinsverantwortlicher);
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, [
            'name' => 'Karl Kasse',
            'rolle' => [(string) $this->rolle(SystemRole::Vereinsverantwortlicher)],
            'kostenstelle' => [(string) $jugendId],
        ]));
        self::assertSame([$jugendId], new UserAccessRepository($this->pdo())->costCenters($id));

        $kostenstellen->update($jugendId, 'Jugend', false);

        $seite = $this->alsAdmin($admin, fn() => $this->dispatch($this->get('/admin/benutzer/' . $id)));
        self::assertStringContainsString('Jugend (inaktiv)', $seite->body);
        self::assertMatchesRegularExpression(
            '/name="kostenstelle\[\]" value="' . $jugendId . '"\s+checked/',
            $seite->body,
        );

        // Saving again (the browser resubmits the checked box) keeps it.
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer/' . $id, [
            'name' => 'Karl Kasse',
            'rolle' => [(string) $this->rolle(SystemRole::Vereinsverantwortlicher)],
            'kostenstelle' => [(string) $jugendId],
        ]));
        self::assertSame([$jugendId], new UserAccessRepository($this->pdo())->costCenters($id), 'assignment survives a deactivated cost center');
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * Invites through the admin page and hands back the new account's id.
     */
    private function einladen(string $email, SystemRole $rolle): int
    {
        $admin = $this->anmelden(self::ADMIN, self::ADMIN_PW);
        $this->alsAdmin($admin, fn() => $this->post('/admin/benutzer', [
            'name' => 'Karl Kasse',
            'email' => $email,
            'rolle' => [(string) $this->rolle($rolle)],
        ]));
        $_SESSION = [];

        return $this->idVon($email);
    }

    /**
     * An invited account that has set its password (and whose second factor
     * is out of the way): active, key pair, no grant.
     */
    private function aktiverBenutzer(string $email, SystemRole $rolle): int
    {
        $ergebnis = $this->einladung()->invite($email, 'Karl Kasse', [$this->rolle($rolle)]);
        self::assertTrue($this->einladung()->complete($ergebnis['token'], self::NEU_PW, self::NEU_PW)->istErfolg());
        $this->pdo()->prepare('UPDATE `user` SET mfa_required = 0 WHERE id = ?')->execute([$ergebnis['userId']]);

        return $ergebnis['userId'];
    }

    private function annehmen(string $token, string $passwort): Response
    {
        $_SESSION = [];
        $session = new Session();
        $session->start();

        return $this->dispatch(new Request(HttpMethod::Post, '/anmelden/einladung', post: [
            '_csrf' => $session->csrfToken(),
            'token' => $token,
            'passwort' => $passwort,
            'passwort_wiederholung' => $passwort,
        ], headers: ['host' => self::HOST], ip: '198.51.100.7'));
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
     * @param array{session: array<mixed>, vault: ?string} $anmeldung
     */
    private function tresorVon(array $anmeldung): Vault
    {
        $_SESSION = $anmeldung['session'];
        $vault = new SessionVault()->unlock($anmeldung['vault']);
        self::assertNotNull($vault, 'Der Tresor ist nicht entsperrt.');

        return $vault;
    }

    /**
     * Runs $aktion in the admin's session and keeps the session's changes
     * (flash, CSRF) visible to the caller afterwards.
     *
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

    private function get(string $pfad, ?string $vaultCookie = null): Request
    {
        $query = [];
        $teile = explode('?', $pfad, 2);
        if (isset($teile[1])) {
            parse_str($teile[1], $query);
        }

        return new Request(
            HttpMethod::Get,
            $teile[0],
            query: $query,
            headers: ['host' => self::HOST],
            ip: '198.51.100.7',
            cookies: self::cookies($vaultCookie),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function cookies(?string $vaultCookie): array
    {
        return $vaultCookie === null ? [] : [Cookie::vaultKey('x', Request::httpsFromGlobals())->name => $vaultCookie];
    }

    private function idVon(string $email): int
    {
        $user = new UserRepository($this->pdo())->findByEmailBlindIndex($this->crypto->blindIndex()->forValue('user.email', $email));
        self::assertNotNull($user, 'Kein Konto für ' . $email);

        return $user->id;
    }

    private function rolle(SystemRole $rolle): int
    {
        $id = new RoleRepository($this->pdo())->findSystem($rolle)?->id;
        self::assertNotNull($id);

        return $id;
    }

    private function letzteMail(): string
    {
        $mail = $this->mailTransport->gesendete[array_key_last($this->mailTransport->gesendete)] ?? null;
        self::assertNotNull($mail, 'Es wurde keine Mail versendet.');

        return $mail->body;
    }

    private function tokenAusMail(): string
    {
        $body = $this->letzteMail();
        self::assertStringContainsString('http://' . self::HOST . '/anmelden/einladung?token=', $body);
        preg_match('/token=([0-9a-f]{64})/', $body, $treffer);
        self::assertArrayHasKey(1, $treffer);

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

    private function zuweisung(): AccessAssignment
    {
        $pdo = $this->pdo();

        return new AccessAssignment($pdo, new RoleRepository($pdo), new UserAccessRepository($pdo), new UserRepository($pdo));
    }

    private function einladung(): Invitation
    {
        return new Invitation($this->pdo(), $this->crypto, new PasswordHasher(), $this->policy(), $this->zuweisung());
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
     * The real route table with the real guard and the controllers this
     * test drives; everything else is unreachable.
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
        $sicherheit = fn(): SecurityController => new SecurityController(
            $view,
            new Session(),
            $mfaService,
            new MfaEnrollment(new MfaTotpRepository($pdo), new MfaBackupCodeRepository($pdo), new UserRepository($pdo), $crypto),
            new UserRepository($pdo),
            $this->mailer(),
            $crypto,
            new PasswordChange($pdo, new PasswordHasher(), $this->policy(), $this->limiter()),
            $audit,
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
        $benutzer = fn(): UserController => new UserController(
            $view,
            new Session(),
            new UserRepository($pdo),
            new RoleRepository($pdo),
            new UserAccessRepository($pdo),
            new AuthTokenRepository($pdo),
            new CostCenterRepository($pdo),
            $this->einladung(),
            $this->verwaltung(),
            $this->zuweisung(),
            $this->mailer(),
            $this->mailSettings(),
            $crypto,
            $this->freigabeHinweis(),
            $audit,
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
        $einladung = fn(): InvitationController => new InvitationController(
            $view,
            new Session(),
            $this->einladung(),
            $this->freigabeHinweis(),
            $this->mailSettings(),
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
            $sicherheit,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $unerreichbar,
            $passwort,
            $unerreichbar,
            $benutzer,
            $tresor,
            $unerreichbar,
            $einladung,
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
