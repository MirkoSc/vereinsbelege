<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Config\Paths;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Domain\AuditAction;
use App\Installer\ConfigWriter;
use App\Installer\InstallController;
use App\Repository\AuditLogRepository;
use App\Repository\MailQueueRepository;
use App\Repository\MfaTotpRepository;
use App\Repository\SettingRepository;
use App\Repository\UserKeyRepository;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\MfaMethod;
use App\Service\Backup\BackupService;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\TresorAnkerException;
use App\Service\Crypto\Vault;
use App\Service\Migration\Migration;
use App\Service\Migration\Migrator;
use App\Service\Migration\SqlSplitter;
use App\Service\Update\DenyHtaccess;
use App\Service\Tresor\Erstbeglaubigung;
use App\Service\Update\UpdateService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;
use App\View\View;

/**
 * The /install flow against a real database, in a throwaway copy of the
 * production directory layout (web/ + current/ + shared/).
 *
 * The acceptance criterion of issue #4 is "the setup.php flow runs through
 * in a fresh container"; this is the part of it that can be automated -
 * everything from the submitted form to the written config. What setup.php
 * itself does (download, checksum, unpack) is covered by
 * UpdateChainTest/ReleaseDownloaderTest, and the manual checklist in the PR
 * covers the browser walk-through.
 */
final class InstallFlowTest extends DatabaseTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/vb_install_' . uniqid('', true);
        mkdir($this->base . '/current', 0775, true);
        mkdir($this->base . '/web', 0775, true);

        // The release under test carries the real migrations; copying them
        // keeps the temporary layout self-contained (Paths resolves them
        // below the release root).
        mkdir($this->base . '/current/migrations', 0775, true);
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $migration) {
            copy($migration, $this->base . '/current/migrations/' . basename($migration));
        }

        // Likewise the common-password list (App\Service\Account\PasswordPolicy):
        // without it a password test would silently only check the length.
        mkdir($this->base . '/current/app/data', 0775, true);
        copy(
            dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt',
            $this->base . '/current/app/data/haeufige-passwoerter.txt',
        );

        // The mail step's test mail renders a template (issue #249).
        mkdir($this->base . '/current/app/views/mail', 0775, true);
        foreach (glob(dirname(__DIR__, 2) . '/app/views/mail/*.php') ?: [] as $vorlage) {
            copy($vorlage, $this->base . '/current/app/views/mail/' . basename($vorlage));
        }

        file_put_contents($this->base . '/web/setup.php', '<?php // bootstrap installer');
    }

    protected function tearDown(): void
    {
        // The first-admin/vault step chain keeps its state in $_SESSION
        // (App\Installer\InstallController::submit()) across the whole
        // PHPUnit process, the same reason the restore tests reset
        // install_restore explicitly - a half-finished setup left behind by
        // one test must not leak into the next one's submit() call.
        unset($_SESSION['install_admin'], $_SESSION['install_restore'], $_SESSION['install_ftp_code']);
        self::removeTree($this->base);
    }

    private function paths(): Paths
    {
        return new Paths($this->base . '/current');
    }

    /**
     * @param bool $echterUploadTest keep PHP's own is_uploaded_file(), which
     *        refuses everything that did not come in through a real upload
     */
    private function controller(bool $echterUploadTest = false, ?FakeMailTransport $mail = null): InstallController
    {
        return new InstallController(
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            $this->paths(),
            new Session(),
            $echterUploadTest ? null : static fn(string $pfad): bool => is_file($pfad),
            $mail ?? new FakeMailTransport([]),
        );
    }

    /**
     * @param array<string, string> $post
     */
    private function submit(array $post): Response
    {
        $session = new Session();
        $session->start();
        $post['_csrf'] = $session->csrfToken();

        $response = $this->controller()->submit(new Request(
            method: HttpMethod::Post,
            path: '/install',
            post: $post,
        ));

        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function validPost(): array
    {
        /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
        $db = self::configData()['db'];

        return [
            'db_host' => $db['host'],
            'db_port' => (string) $db['port'],
            'db_name' => $db['name'],
            'db_user' => $db['user'],
            'db_password' => $db['password'],
            'kanal' => 'beta',
            'admin_email' => 'admin@verein.example',
            'admin_name' => 'Admina Beispiel',
            'admin_password' => 'sicheres-langes-testpasswort',
            'admin_password_wiederholung' => 'sicheres-langes-testpasswort',
        ];
    }

    /**
     * The recovery key's seven groups, as printed on the confirmation page
     * (app/views/install.php, "schluessel" step).
     *
     * @return list<string>
     */
    private function extractGroups(string $html): array
    {
        preg_match_all('/<span class="schluessel-gruppe">([^<]+)<\/span>/', $html, $treffer);

        return $treffer[1];
    }

    /**
     * Confirms the key and - unless $mailSchritt - skips the mail step that
     * follows (issue #249), so the result is the finished page.
     */
    private function confirmKey(string $letzteGruppe, bool $mailSchritt = false): Response
    {
        $session = new Session();
        $session->start();

        $response = $this->controller()->confirmKey(new Request(
            method: HttpMethod::Post,
            path: '/install/schluessel',
            post: ['_csrf' => $session->csrfToken(), 'schluessel_letzte_gruppe' => $letzteGruppe],
        ));
        self::assertInstanceOf(Response::class, $response);

        if ($mailSchritt || $response->status !== 200) {
            return $response;
        }
        self::assertStringContainsString('Mail-Versand (optional)', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile(), 'config.php waits for the mail step');

        return $this->mailStep(['aktion' => 'ueberspringen']);
    }

    /**
     * @param array<string, string> $post
     */
    private function mailStep(array $post, ?FakeMailTransport $transport = null, bool $mitToken = true): Response
    {
        $session = new Session();
        $session->start();
        if ($mitToken) {
            $post['_csrf'] = $session->csrfToken();
        }

        $response = $this->controller(mail: $transport)->mail(new Request(
            method: HttpMethod::Post,
            path: '/install/mail',
            post: $post,
        ));
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function mailPost(string $aktion): array
    {
        return [
            'aktion' => $aktion,
            'transport' => 'smtp',
            'host' => 'mail.example.test',
            'port' => '465',
            'sicherheit' => 'implizit',
            'benutzer' => 'versand@example.test',
            'passwort' => 'smtp-geheim-4711',
            'absender' => 'versand@example.test',
            'vereinsname' => 'Beispielverein',
            'oeffentliche_url' => 'https://belege.example.test',
            'empfaenger' => 'kassierer@example.test',
        ];
    }

    private function restart(): Response
    {
        $session = new Session();
        $session->start();

        $response = $this->controller()->restart(new Request(
            method: HttpMethod::Post,
            path: '/install/neu',
            post: ['_csrf' => $session->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function userCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM `user`')->fetchColumn();
    }

    public function testTheFormIsShownWhileNoConfigExists(): void
    {
        $response = $this->controller()->form(new Request(HttpMethod::Get, '/install'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Datenbankname', $response->body);
    }

    /**
     * Issue #101: the installer repeats what setup.php did - a deny-all
     * .htaccess in shared/, releases/ and current/ - and stays quiet when it
     * worked.
     */
    public function testTheInstallerLocksSharedAndReleases(): void
    {
        mkdir($this->base . '/shared', 0775, true);
        mkdir($this->base . '/releases', 0775, true);

        $response = $this->controller()->form(new Request(HttpMethod::Get, '/install'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/shared/.htaccess'));
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/releases/.htaccess'));
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/current/.htaccess'));
        self::assertFileDoesNotExist($this->base . '/web/.htaccess', 'the docroot stays public');
        self::assertStringNotContainsString('Zugriffssperre fehlt', $response->body);
    }

    /**
     * ... and when it cannot write the file it does not stop, but says so
     * (a directory in the file's place stands in for missing write access).
     */
    public function testAMissingLockIsAVisibleWarningNotAnAbort(): void
    {
        mkdir($this->base . '/shared/.htaccess', 0775, true);

        $response = $this->controller()->form(new Request(HttpMethod::Get, '/install'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Zugriffssperre fehlt', $response->body);
        self::assertStringContainsString('shared/ (Datei nicht schreibbar', $response->body);
        self::assertStringContainsString('Datenbankname', $response->body, 'the form is still there');
    }

    /**
     * setup.php asks for the channel before there is a database to hold it,
     * so it leaves the answer in shared/ - otherwise an instance installed
     * from a pre-release would look for updates on stable afterwards.
     */
    public function testTheChannelChosenInSetupIsPreselected(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        file_put_contents($this->paths()->setupChannelFile(), 'beta');

        $response = $this->controller()->form(new Request(HttpMethod::Get, '/install'));

        self::assertInstanceOf(Response::class, $response);
        self::assertMatchesRegularExpression('/value="beta"\s*selected/', $response->body);
    }

    /**
     * The fresh-install flow is itself a short step chain (docs/spec/06-betrieb.md
     * section 1, docs/spec/01-sicherheit.md section 2): submitting the form
     * creates the vault and the admin but not config.php yet - only once the
     * printed recovery key's last group is typed back is the installation
     * closed.
     */
    public function testAFreshInstallCreatesSchemaConfigAndSetting(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        file_put_contents($this->paths()->setupChannelFile(), 'beta');

        $response = $this->submit($this->validPost());
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Wiederherstellungsschlüssel', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile(), 'config.php is written only once the key is confirmed');

        $gruppen = $this->extractGroups($response->body);
        self::assertCount(7, $gruppen);

        $fertig = $this->confirmKey($gruppen[array_key_last($gruppen)]);
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);

        // config.php exists and is readable by the application
        $config = Config::fromFile($this->paths()->configFile());
        self::assertSame(self::dbName(), $config->dbName);
        self::assertNotSame('', $config->cronToken);
        // Issue #244: the finished page names the cron URL with its token.
        self::assertStringContainsString('value="http://localhost/cron?token=' . $config->cronToken . '"', $fertig->body);

        // every migration applied, including the user/vault tables of M3-2
        $tables = $this->pdo()
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('schema_version', $tables);
        self::assertContains('setting', $tables);
        self::assertContains('user', $tables);
        self::assertContains('user_key', $tables);
        self::assertContains('vault_grant', $tables);

        self::assertSame(
            'beta',
            new SettingRepository($this->pdo())->get(UpdateService::SETTING_CHANNEL),
            'the channel from setup.php is carried into the installation',
        );

        // setup.php is a second, unauthenticated entry point and has to go
        self::assertFileDoesNotExist($this->base . '/web/setup.php');
        self::assertFileDoesNotExist($this->paths()->setupChannelFile());
    }

    public function testTheMailStepFollowsTheKeyAndCanBeSkipped(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);

        $mail = $this->confirmKey($gruppen[6], mailSchritt: true);
        self::assertSame(200, $mail->status);
        self::assertStringContainsString('action="/install/mail"', $mail->body);
        self::assertStringContainsString('value="' . $this->validPost()['admin_email'] . '"', $mail->body, 'the admin address is the suggested sender');
        self::assertFileDoesNotExist($this->paths()->configFile());

        // Reloading the page or confirming again lands on the mail step, not
        // on the key confirmation.
        $neuGeladen = $this->controller()->form(new Request(HttpMethod::Get, '/install'));
        self::assertInstanceOf(Response::class, $neuGeladen);
        self::assertStringContainsString('Mail-Versand (optional)', $neuGeladen->body);
        self::assertStringContainsString('Mail-Versand (optional)', $this->confirmKey('AAAAAAAA', mailSchritt: true)->body);

        $fertig = $this->mailStep(['aktion' => 'ueberspringen']);
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);
        self::assertStringContainsString('Mail ist noch nicht eingerichtet', $fertig->body);
        self::assertFileExists($this->paths()->configFile());
        self::assertSame('', new SettingRepository($this->pdo())->get('mail_smtp_host'));
    }

    public function testTheMailStepSavesTheSettingsSendsATestMailAndFinishes(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);
        $this->confirmKey($gruppen[6], mailSchritt: true);

        $transport = new FakeMailTransport([true]);
        $test = $this->mailStep(self::mailPost('testen'), $transport);
        self::assertSame(200, $test->status);
        self::assertStringContainsString('Testmail wurde versendet', $test->body);
        self::assertCount(1, $transport->gesendete);
        self::assertSame('kassierer@example.test', $transport->gesendete[0]->to);
        self::assertStringNotContainsString('smtp-geheim-4711', $test->body);
        self::assertStringContainsString('Ein Passwort ist gespeichert', $test->body);
        self::assertFileDoesNotExist($this->paths()->configFile(), 'a test mail does not finish the installation');
        self::assertStringNotContainsString('smtp-geheim-4711', serialize($_SESSION), 'the SMTP password never enters the session');

        $gespeichert = new SettingRepository($this->pdo())->get('mail_smtp_passwort_enc');
        self::assertNotSame('', $gespeichert);
        self::assertStringNotContainsString('smtp-geheim-4711', $gespeichert);

        // Finishing with a blank password field keeps the saved one.
        $fertig = $this->mailStep([...self::mailPost('abschliessen'), 'passwort' => '  ']);
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);
        self::assertStringNotContainsString('Mail ist noch nicht eingerichtet', $fertig->body);

        // The password opens with the server key config.php got.
        $serverKey = new ServerCrypto((string) base64_decode((string) (require $this->paths()->configFile())['server_key'], true));
        $settings = new SettingRepository($this->pdo());
        self::assertSame('smtp-geheim-4711', $serverKey->decrypt((string) base64_decode($settings->get('mail_smtp_passwort_enc'), true)));
        self::assertSame('mail.example.test', $settings->get('mail_smtp_host'));
        self::assertSame('https://belege.example.test', $settings->get('oeffentliche_url'));
    }

    public function testSkippingAfterASuccessfulTestKeepsTheSettingsAndSaysSo(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);
        $this->confirmKey($gruppen[6], mailSchritt: true);
        $this->mailStep(self::mailPost('testen'), new FakeMailTransport([true]));

        $fertig = $this->mailStep(['aktion' => 'ueberspringen']);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);
        self::assertStringNotContainsString('Mail ist noch nicht eingerichtet', $fertig->body);
        self::assertSame('mail.example.test', new SettingRepository($this->pdo())->get('mail_smtp_host'));
    }

    public function testAStaleRestartAfterTheConfirmationRemovesNothing(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);
        $this->confirmKey($gruppen[6], mailSchritt: true);

        $antwort = $this->restart();
        self::assertStringContainsString('Mail-Versand (optional)', $antwort->body);
        self::assertSame(1, $this->userCount());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM vault')->fetchColumn());
    }

    public function testAFailedTestMailIsShownAndTheStepStaysOpen(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);
        $this->confirmKey($gruppen[6], mailSchritt: true);

        $test = $this->mailStep(self::mailPost('testen'), new FakeMailTransport([false]));
        self::assertSame(200, $test->status);
        self::assertStringContainsString('Testmail konnte nicht versendet werden', $test->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testAnInvalidMailStepKeepsTheInputButNeverThePassword(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $gruppen = $this->extractGroups($this->submit($this->validPost())->body);
        $this->confirmKey($gruppen[6], mailSchritt: true);

        $falsch = $this->mailStep([...self::mailPost('abschliessen'), 'absender' => 'keine-adresse']);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('gültige Absenderadresse', $falsch->body);
        self::assertStringContainsString('value="mail.example.test"', $falsch->body);
        self::assertStringNotContainsString('smtp-geheim-4711', $falsch->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertSame('', new SettingRepository($this->pdo())->get('mail_smtp_host'), 'nothing was saved');

        $ohneEmpfaenger = $this->mailStep([...self::mailPost('testen'), 'empfaenger' => '']);
        self::assertSame(422, $ohneEmpfaenger->status);

        $unbekannt = $this->mailStep(['aktion' => 'irgendwas']);
        self::assertSame(422, $unbekannt->status);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testTheMailStepNeedsCsrfAndAConfirmedKey(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);

        self::assertSame(409, $this->mailStep(['aktion' => 'ueberspringen'])->status, 'no setup at all');

        // Submitted, but the key is not confirmed yet: skipping the mail
        // step must not skip the confirmation.
        $this->submit($this->validPost());
        self::assertSame(409, $this->mailStep(['aktion' => 'ueberspringen'])->status);
        self::assertSame(403, $this->mailStep(['aktion' => 'ueberspringen'], mitToken: false)->status);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testTheRecoveryKeyPageShowsSevenGroupsOfEightCharacters(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);

        $response = $this->submit($this->validPost());
        $gruppen = $this->extractGroups($response->body);

        self::assertCount(7, $gruppen);
        foreach ($gruppen as $gruppe) {
            self::assertSame(8, strlen($gruppe));
        }
    }

    /**
     * The row is encrypted with the SERVER key, not the vault (CLAUDE.md
     * section 4: the login lookup has to work without a session) - and the
     * blind index is the one the login (M3-3) will search on.
     */
    public function testTheAdminRowIsEncryptedWithTheServerKeyAndSearchableByItsBlindIndex(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();

        $response = $this->submit($post);
        $this->confirmKey($this->extractGroups($response->body)[6]);

        $config = Config::fromFile($this->paths()->configFile());
        $crypto = new ServerCrypto($config->serverKey);

        $zeile = $this->pdo()->query('SELECT email_enc, email_bi, display_name_enc, password_hash FROM `user`')->fetch();
        self::assertIsArray($zeile);
        self::assertSame($post['admin_email'], $crypto->decrypt((string) $zeile['email_enc']));
        self::assertSame($post['admin_name'], $crypto->decrypt((string) $zeile['display_name_enc']));
        self::assertSame(
            $crypto->blindIndex()->forValue('user.email', $post['admin_email']),
            $zeile['email_bi'],
        );
        self::assertTrue(password_verify($post['admin_password'], (string) $zeile['password_hash']));

        foreach ($zeile as $wert) {
            if (is_string($wert)) {
                self::assertStringNotContainsString($post['admin_email'], $wert);
            }
        }
    }

    /**
     * "ohne Grant kein Entschlüsseln" (docs/spec/01-sicherheit.md section 2,
     * "Pflicht-Tests"): the wrapped user key opens with the admin's own
     * password, and what it unlocks is a key pair that in turn opens the
     * grant into a vault matching `vault.public_key`.
     */
    public function testTheVaultGrantUnwrapsWithThePasswordAndMatchesTheStoredVault(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();

        $response = $this->submit($post);
        $this->confirmKey($this->extractGroups($response->body)[6]);

        $userId = (int) $this->pdo()->query('SELECT id FROM `user`')->fetchColumn();

        $userKey = new UserKeyRepository($this->pdo())->forUser($userId);
        self::assertNotNull($userKey);
        $userPair = $userKey->unwrap($post['admin_password']);

        $grant = new VaultGrantRepository($this->pdo())->forUser($userId);
        self::assertNotNull($grant);

        $vaultZeile = $this->pdo()->query('SELECT public_key FROM vault ORDER BY version DESC LIMIT 1')->fetch();
        self::assertIsArray($vaultZeile);

        $vault = $grant->open($userPair, (string) $vaultZeile['public_key']);
        self::assertTrue($vault->isUnlocked());
    }

    public function testAWrongPasswordCannotUnwrapTheAdminsKey(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $response = $this->submit($this->validPost());
        $this->confirmKey($this->extractGroups($response->body)[6]);

        $userId = (int) $this->pdo()->query('SELECT id FROM `user`')->fetchColumn();
        $userKey = new UserKeyRepository($this->pdo())->forUser($userId);
        self::assertNotNull($userKey);

        $this->expectException(CryptoException::class);
        $userKey->unwrap('ganz-und-gar-das-falsche-passwort');
    }

    /**
     * The recovery key (docs/spec/01-sicherheit.md section 2,
     * "Wiederherstellungsschlüssel-Flow") opens the very same vault the
     * admin's own grant does, and is never itself written to any column -
     * only the hash of its last group lives in the session while the setup
     * is pending.
     */
    public function testTheRecoveryKeyOpensTheSameVaultAndIsStoredNowhere(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $response = $this->submit($this->validPost());
        $gruppen = $this->extractGroups($response->body);
        $letzteGruppe = $gruppen[6];

        $this->confirmKey($letzteGruppe);

        $vaultZeile = $this->pdo()->query('SELECT version, public_key FROM vault ORDER BY version DESC LIMIT 1')->fetch();
        self::assertIsArray($vaultZeile);

        $recoveryKey = RecoveryKey::parse(implode(' ', $gruppen));
        $vault = $recoveryKey->openVault((string) $vaultZeile['public_key'], (int) $vaultZeile['version']);
        self::assertTrue($vault->isUnlocked());

        foreach (['user', 'user_key', 'vault_grant', 'vault'] as $tabelle) {
            foreach ($this->pdo()->query(sprintf('SELECT * FROM `%s`', $tabelle))->fetchAll() as $zeile) {
                foreach ($zeile as $wert) {
                    if (is_string($wert)) {
                        self::assertStringNotContainsString($letzteGruppe, $wert);
                    }
                }
            }
        }
    }

    public function testAWrongLastGroupIsRejectedAndTheKeyIsNotShownAgain(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $response = $this->submit($this->validPost());
        $gruppen = $this->extractGroups($response->body);

        $falsch = $this->confirmKey('AAAAAAAA');
        self::assertSame(422, $falsch->status);
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertStringNotContainsString($gruppen[0], $falsch->body, 'the recovery key is never shown a second time');
        self::assertStringContainsString('stimmt nicht', $falsch->body);

        // a second, correct attempt still finishes the installation
        $fertig = $this->confirmKey($gruppen[6]);
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Installation abgeschlossen', $fertig->body);
    }

    /**
     * A resubmission (double click, browser back button) must not seal a
     * second vault nobody could ever be granted access to.
     */
    public function testResubmittingWhilePendingDoesNotCreateASecondAdmin(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $this->submit($this->validPost());
        self::assertSame(1, $this->userCount());

        $zweite = $this->submit($this->validPost());
        self::assertSame(200, $zweite->status);
        self::assertStringContainsString('noch nicht bestätigt', $zweite->body);
        self::assertSame(1, $this->userCount(), 'no second admin was created');
    }

    public function testRestartRemovesTheHalfFinishedAdminAndAllowsARetry(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $this->submit($this->validPost());
        self::assertSame(1, $this->userCount());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM vault')->fetchColumn());

        $neu = $this->restart();
        self::assertSame(200, $neu->status);
        self::assertSame(0, $this->userCount());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM vault')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM user_key')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM vault_grant')->fetchColumn());

        $wieder = $this->submit($this->validPost());
        self::assertSame(200, $wieder->status);
        self::assertStringContainsString('Wiederherstellungsschlüssel', $wieder->body);
        self::assertSame(1, $this->userCount());
    }

    public function testAFreshInstallRefusesADatabaseThatAlreadyHasAVault(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        new VaultRepository($this->pdo())->insert(str_repeat('a', 32));

        $response = $this->submit($this->validPost());

        self::assertSame(422, $response->status);
        self::assertStringContainsString('bereits eine Installation', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertSame(0, $this->userCount());
    }

    public function testConfirmKeyNeedsCsrfAndAnActiveSetup(): void
    {
        $ohneToken = $this->controller()->confirmKey(new Request(HttpMethod::Post, '/install/schluessel'));
        self::assertSame(403, $ohneToken->status);

        $session = new Session();
        $session->start();
        unset($_SESSION['install_admin']);
        $nichtAktiv = $this->controller()->confirmKey(new Request(
            method: HttpMethod::Post,
            path: '/install/schluessel',
            post: ['_csrf' => $session->csrfToken()],
        ));
        self::assertSame(409, $nichtAktiv->status);
    }

    public function testRestartNeedsCsrfAndAnActiveSetup(): void
    {
        $ohneToken = $this->controller()->restart(new Request(HttpMethod::Post, '/install/neu'));
        self::assertSame(403, $ohneToken->status);

        $session = new Session();
        $session->start();
        unset($_SESSION['install_admin']);
        $nichtAktiv = $this->controller()->restart(new Request(
            method: HttpMethod::Post,
            path: '/install/neu',
            post: ['_csrf' => $session->csrfToken()],
        ));
        self::assertSame(409, $nichtAktiv->status);
    }

    public function testAnInvalidEmailIsRejectedAndNothingIsWritten(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();
        $post['admin_email'] = 'keine-email-adresse';

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('gültige E-Mail-Adresse', $response->body);
        // Rejected before the database is even touched (like the db_name
        // check above), so there is no `user` table yet to query.
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testATooShortPasswordIsRejected(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();
        $post['admin_password'] = 'kurz1234567';
        $post['admin_password_wiederholung'] = 'kurz1234567';

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('mindestens 12 Zeichen', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testACommonPasswordIsRejectedEvenIfLongEnough(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();
        // 12 characters, and in app/data/haeufige-passwoerter.txt.
        $post['admin_password'] = 'passwort1234';
        $post['admin_password_wiederholung'] = 'passwort1234';

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('zu bekannt', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testMismatchedPasswordRepetitionIsRejected(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $post = $this->validPost();
        $post['admin_password_wiederholung'] = 'ein-ganz-anderes-testpasswort';

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('nicht überein', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testIncompleteCredentialsAreRejectedWithoutTouchingAnything(): void
    {
        $post = $this->validPost();
        $post['db_name'] = '';

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Datenbankname', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testAFailingConnectionIsReportedAndNothingIsWritten(): void
    {
        $post = $this->validPost();
        $post['db_password'] = 'falsch-' . bin2hex(random_bytes(4));
        $post['db_user'] = 'niemand-' . bin2hex(random_bytes(4));

        $response = $this->submit($post);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Verbindung fehlgeschlagen', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    /**
     * The installer writes the config and creates the schema, so it is a
     * write route like any other - a foreign page must not be able to
     * trigger it in an admin's browser.
     */
    public function testAMissingCsrfTokenStopsTheInstallation(): void
    {
        $response = $this->controller()->submit(new Request(
            method: HttpMethod::Post,
            path: '/install',
            post: $this->validPost(),
        ));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->status);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    /**
     * A backup ZIP of the current test database, as the update chain or
     * bin/backup.php would make it.
     */
    private function makeBackup(bool $mitConfig, ?string $configDatei = null): string
    {
        $quelle = $configDatei ?? $this->base . '/quelle_config.php';
        if ($configDatei === null) {
            /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
            $db = self::configData()['db'];
            ConfigWriter::write($quelle, $db);
        }

        // The blob directory of the installation the backup came from - not
        // the one of the installation being restored into.
        $service = new BackupService(
            $this->pdo(),
            $this->base . '/backups',
            $quelle,
            '0.0.9-alt',
            $this->base . '/quelle_blobs',
        );

        return $this->base . '/backups/' . $service->create($mitConfig);
    }

    private function wipeDatabase(): void
    {
        // Foreign keys off while dropping: SHOW TABLES is alphabetical, not
        // in dependency order (file_blob before file_blob_chunk).
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $table));
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<string, string> $post
     */
    private function submitRestore(string $zip, array $post = [], bool $echterUploadTest = false): Response
    {
        $session = new Session();
        $session->start();

        $response = $this->controller($echterUploadTest)->submit(new Request(
            method: HttpMethod::Post,
            path: '/install',
            post: [...$this->validPost(), 'modus' => 'restore', '_csrf' => $session->csrfToken(), ...$post],
            files: ['backup' => ['error' => \UPLOAD_ERR_OK, 'tmp_name' => $zip, 'name' => 'b.zip']],
        ));
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * Calls the step endpoint like install.js until it reports "fertig".
     *
     * @return array<string, mixed> the last answer
     */
    private function runRestoreSteps(): array
    {
        $session = new Session();
        $session->start();

        for ($i = 0; $i < 50; $i++) {
            $response = $this->controller()->restoreStep(new Request(
                method: HttpMethod::Post,
                path: '/install/wiederherstellen',
                headers: ['x-csrf-token' => $session->csrfToken()],
            ));
            self::assertInstanceOf(Response::class, $response);
            $antwort = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $response->status, (string) ($antwort['fehler'] ?? ''));
            if ($antwort['fertig']) {
                return $antwort;
            }
        }
        self::fail('the restore did not finish');
    }

    public function testARestoreBringsBackDataAndTheServerKey(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        new SettingRepository($this->pdo())->set('probe', "Grün; 'zitiert'");
        $zip = $this->makeBackup(mitConfig: true);
        $alterSchluessel = (require $this->base . '/quelle_config.php')['server_key'];

        $this->wipeDatabase();
        mkdir($this->paths()->sharedDir(), 0775, true);
        file_put_contents($this->paths()->setupChannelFile(), 'beta');

        $response = $this->submitRestore($zip);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Version 0.0.9-alt', $response->body);
        self::assertStringContainsString('Server-Schlüssel der alten Installation', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile(), 'the config is written last');

        $ende = $this->runRestoreSteps();

        self::assertTrue($ende['fertig']);
        self::assertSame("Grün; 'zitiert'", new SettingRepository($this->pdo())->get('probe'));
        self::assertSame('beta', new SettingRepository($this->pdo())->get(UpdateService::SETTING_CHANNEL));

        $config = require $this->paths()->configFile();
        self::assertSame($alterSchluessel, $config['server_key'], 'the old server key is kept');
        self::assertNotSame('', Config::fromFile($this->paths()->configFile())->cronToken);

        // Issue #244: the restore wrote a new cron token, and the last step
        // hands the page the new cron URL to show.
        $quelle = require $this->base . '/quelle_config.php';
        self::assertNotSame($quelle['cron_token'], $config['cron_token'], 'a restore gets a new cron token');
        self::assertSame('http://localhost/cron?token=' . $config['cron_token'], $ende['cron_url'] ?? null);

        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: [], 'temporary dump removed');
        self::assertFileDoesNotExist($this->base . '/web/setup.php');
    }

    /**
     * Issue #120: the dump phase keeps a byte offset in the session. A
     * restore session of an older version still counts statements under
     * `offset` - it is carried on from exactly that statement, nothing runs
     * twice (a second CREATE TABLE would fail) and nothing is left out.
     */
    public function testARestoreSessionThatCountsStatementsIsCarriedOn(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        new SettingRepository($this->pdo())->set('probe', "Grün; 'zitiert'");
        $zip = $this->makeBackup(mitConfig: true);
        $this->wipeDatabase();

        self::assertSame(200, $this->submitRestore($zip)->status);
        self::assertSame(0, $_SESSION['install_restore']['dump_offset']);

        // What an older version would have left after its first statements.
        $dumpDatei = (string) $_SESSION['install_restore']['dump_datei'];
        $statements = SqlSplitter::split((string) file_get_contents($dumpDatei));
        $bisher = 0;
        foreach ($statements as $statement) {
            $this->pdo()->exec($statement);
            $bisher++;
            if (str_starts_with($statement, 'CREATE TABLE')) {
                break;
            }
        }
        unset($_SESSION['install_restore']['dump_offset'], $_SESSION['install_restore']['anweisungen']);
        $_SESSION['install_restore']['offset'] = $bisher;

        $ende = $this->runRestoreSteps();

        self::assertTrue($ende['fertig']);
        self::assertSame("Grün; 'zitiert'", new SettingRepository($this->pdo())->get('probe'));
        self::assertFileExists($this->paths()->configFile());
    }

    /**
     * A backup with blobs restores in two phases (M2-6): the statements of
     * dump.sql first, then the encrypted files - and the files land under the
     * names the application builds, below shared/var/blobs/.
     */
    public function testARestoreBringsBackTheBlobFiles(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();

        $name = str_repeat('7f', 16);
        mkdir($this->base . '/quelle_blobs/' . substr($name, 0, 2), 0775, true);
        file_put_contents($this->base . '/quelle_blobs/' . substr($name, 0, 2) . '/' . $name, 'Chiffrat einer Rechnung');

        $zip = $this->makeBackup(mitConfig: false);
        $this->wipeDatabase();

        self::assertSame(200, $this->submitRestore($zip)->status);
        $ende = $this->runRestoreSteps();

        self::assertTrue($ende['fertig']);
        self::assertSame('blobs', $ende['phase'], 'the blob phase is the last one');
        self::assertSame(
            'Chiffrat einer Rechnung',
            file_get_contents($this->paths()->blobDir() . '/' . substr($name, 0, 2) . '/' . $name),
        );
        self::assertSame(
            [],
            glob($this->paths()->varDir() . '/install_restore_*') ?: [],
            'neither the temporary dump nor the temporary ZIP is left behind',
        );
    }

    /**
     * @param array<string, string> $post
     */
    private function submitServerRestore(string $name, array $post = []): Response
    {
        $session = new Session();
        $session->start();

        $response = $this->controller()->submit(new Request(
            method: HttpMethod::Post,
            path: '/install',
            post: [...$this->validPost(), 'modus' => 'restore', '_csrf' => $session->csrfToken(), 'backup_server' => $name, ...$post],
        ));
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * The FTP proof of this session (issue #244): the name of the file the
     * form asks for, created as the admin would by FTP.
     */
    private function ftpFreigabeAnlegen(): string
    {
        $formular = $this->controller()->form(new Request(HttpMethod::Get, '/install'));
        self::assertInstanceOf(Response::class, $formular);
        self::assertSame(1, preg_match('#<code>(freigabe-[0-9a-f]{16}\.txt)</code>#', $formular->body, $treffer));
        if (!is_dir($this->paths()->backupDir())) {
            mkdir($this->paths()->backupDir(), 0775, true);
        }
        touch($this->paths()->backupDir() . '/' . $treffer[1]);

        return $treffer[1];
    }

    /**
     * Issue #244 (E-66): a backup larger than upload_max_filesize goes into
     * shared/var/backups/ by FTP and is chosen in the form. It is read in
     * place - not copied for the blob phase - and stays there afterwards.
     */
    public function testABackupPutIntoSharedVarBackupsByFtpIsRestoredInPlaceAndKept(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        new SettingRepository($this->pdo())->set('probe', 'vom FTP');
        $name = str_repeat('3c', 16);
        mkdir($this->base . '/quelle_blobs/' . substr($name, 0, 2), 0775, true);
        file_put_contents($this->base . '/quelle_blobs/' . substr($name, 0, 2) . '/' . $name, 'Chiffrat per FTP');
        $zip = $this->makeBackup(mitConfig: true);
        $this->wipeDatabase();

        mkdir($this->paths()->backupDir(), 0775, true);
        $abgelegt = $this->paths()->backupDir() . '/' . basename($zip);
        copy($zip, $abgelegt);
        $inhalt = hash_file('sha256', $abgelegt);

        $freigabe = $this->ftpFreigabeAnlegen();
        $formular = $this->controller()->form(new Request(HttpMethod::Get, '/install'));
        self::assertInstanceOf(Response::class, $formular);
        self::assertStringContainsString('<option value="' . basename($zip) . '"', $formular->body, 'the form offers it');
        self::assertStringNotContainsString('<option value="' . $freigabe, $formular->body, 'only ZIPs are offered');

        $response = $this->submitServerRestore(basename($zip));
        self::assertSame(200, $response->status, $response->body);
        self::assertSame($abgelegt, $_SESSION['install_restore']['zip_datei'] ?? null, 'read in place, not copied');
        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*.zip') ?: []);
        self::assertFileDoesNotExist($this->paths()->backupDir() . '/' . $freigabe, 'the proof is used up');

        $ende = $this->runRestoreSteps();

        self::assertTrue($ende['fertig']);
        self::assertSame('blobs', $ende['phase']);
        self::assertSame('vom FTP', new SettingRepository($this->pdo())->get('probe'));
        self::assertSame('Chiffrat per FTP', file_get_contents($this->paths()->blobDir() . '/' . substr($name, 0, 2) . '/' . $name));
        self::assertFileExists($abgelegt, 'the admin\'s file stays');
        self::assertSame($inhalt, hash_file('sha256', $abgelegt));
        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: [], 'only the temporary dump was removed');
        self::assertFileExists($this->paths()->configFile());
    }

    /**
     * The installer answers without a login: the chosen name is a client's
     * claim and never leads out of shared/var/backups/.
     */
    public function testTheChosenServerBackupNeverLeadsOutOfTheBackupDirectory(): void
    {
        $this->ftpFreigabeAnlegen();
        $aussen = $this->paths()->varDir() . '/aussen.zip';
        $archiv = new \ZipArchive();
        $archiv->open($aussen, \ZipArchive::CREATE);
        $archiv->addFromString('dump.sql', 'SELECT 1;');
        $archiv->close();
        symlink($aussen, $this->paths()->backupDir() . '/verweis.zip');
        copy($aussen, $this->paths()->backupDir() . '/.versteckt.zip');

        foreach (['../aussen.zip', '/etc/passwd', 'verweis.zip', '.versteckt.zip', 'fehlt.zip', 'aussen'] as $name) {
            $response = $this->submitServerRestore($name);
            self::assertSame(422, $response->status, $name);
            self::assertStringContainsString('liegt nicht (mehr) in shared/var/backups/', $response->body, $name);
            self::assertArrayNotHasKey('install_restore', $_SESSION, $name);
        }
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: []);
    }

    /**
     * Without the FTP proof of its own session, nobody gets to see or play
     * in a backup from shared/var/backups/: /install answers without a
     * login, and the form names the database the dump goes into. A proof
     * made for another session does not count.
     */
    public function testAServerBackupNeedsTheFtpProofOfThisSession(): void
    {
        mkdir($this->paths()->backupDir(), 0775, true);
        $zip = $this->paths()->backupDir() . '/backup_20261001_120000.zip';
        $archiv = new \ZipArchive();
        $archiv->open($zip, \ZipArchive::CREATE);
        $archiv->addFromString('dump.sql', 'SELECT 1;');
        $archiv->close();
        touch($this->paths()->backupDir() . '/freigabe-' . str_repeat('0', 16) . '.txt');

        $formular = $this->controller()->form(new Request(HttpMethod::Get, '/install'));
        self::assertInstanceOf(Response::class, $formular);
        self::assertStringNotContainsString('backup_20261001_120000.zip', $formular->body, 'the directory is not listed');
        self::assertStringContainsString('leere Datei', $formular->body);

        $response = $this->submitServerRestore('backup_20261001_120000.zip');
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Zuerst per FTP die leere Datei freigabe-', $response->body);
        self::assertArrayNotHasKey('install_restore', $_SESSION);
        self::assertStringNotContainsString('<option value="backup_20261001_120000.zip"', $response->body);
    }

    /** Upload and choice together are refused instead of silently picking one. */
    public function testAnUploadAndAServerBackupTogetherAreRefused(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        $zip = $this->makeBackup(mitConfig: true);
        $this->ftpFreigabeAnlegen();
        copy($zip, $this->paths()->backupDir() . '/' . basename($zip));

        $response = $this->submitRestore($zip, ['backup_server' => basename($zip)]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('nicht beides', $response->body);
        self::assertArrayNotHasKey('install_restore', $_SESSION);
    }

    // ------------------------------------------------- restore without key

    /**
     * A working installation with one admin (fresh install through the real
     * flow), an authenticator app and the printed recovery key - then a
     * backup WITHOUT config.php of it, the database wiped and config.php
     * removed, as on a new server. Hands back what the admin would have on
     * paper: the recovery key's groups, plus the old server key for the
     * tests that play "I kept a copy of config.php".
     *
     * @param (\Closure(): void)|null $vorDemBackup changes the database
     *        just before the backup is made (issue #240)
     *
     * @return array{zip: string, gruppen: list<string>, alterSchluessel: string, userId: int}
     */
    private function installationOhneSchluesselGesichert(?\Closure $vorDemBackup = null, bool $mitConfig = false): array
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $response = $this->submit($this->validPost());
        $gruppen = $this->extractGroups($response->body);
        $this->confirmKey($gruppen[6]);

        $alterSchluessel = (string) (require $this->paths()->configFile())['server_key'];
        $alt = new ServerCrypto((string) base64_decode($alterSchluessel, true));
        $userId = (int) $this->pdo()->query('SELECT id FROM `user`')->fetchColumn();

        // The admin had set up an authenticator app: its secret is server-key
        // ciphertext like the address.
        new MfaTotpRepository($this->pdo())->putConfirmed($userId, $alt->encrypt('JBSWY3DPEHPK3PXP'), 0);
        new MfaTotpRepository($this->pdo())->confirm($userId);
        new UserRepository($this->pdo())->updateMfaMethod($userId, MfaMethod::Totp);
        // ... and a mail still waiting in the queue, ciphertext under the same key.
        new MailQueueRepository($this->pdo(), $alt)->enqueue('jemand@verein.example', 'Betreff', 'Text');

        if ($vorDemBackup !== null) {
            $vorDemBackup();
        }
        $zip = $this->makeBackup($mitConfig, $mitConfig ? $this->paths()->configFile() : null);
        $this->wipeDatabase();
        unlink($this->paths()->configFile());
        // The fresh install removed setup.php; the new server has one again.
        file_put_contents($this->base . '/web/setup.php', '<?php // bootstrap installer');

        return ['zip' => $zip, 'gruppen' => $gruppen, 'alterSchluessel' => $alterSchluessel, 'userId' => $userId];
    }

    /**
     * @param array<string, string> $post
     */
    private function postAccess(string $pfad, array $post): Response
    {
        $session = new Session();
        $session->start();
        $controller = $this->controller();
        $request = new Request(
            method: HttpMethod::Post,
            path: $pfad,
            post: [...$post, '_csrf' => $session->csrfToken()],
            ip: '203.0.113.5',
        );
        $response = $pfad === '/install/zugang' ? $controller->access($request) : $controller->serverKey($request);
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * Restores the key-less backup up to the access step and checks the
     * state there: data in, config.php NOT written, installer still open.
     *
     * @param (\Closure(): void)|null $vorDemBackup see installationOhneSchluesselGesichert()
     *
     * @return array{zip: string, gruppen: list<string>, alterSchluessel: string, userId: int}
     */
    private function bisZumZugangsschritt(?\Closure $vorDemBackup = null): array
    {
        $sicherung = $this->installationOhneSchluesselGesichert($vorDemBackup);

        $response = $this->submitRestore($sicherung['zip']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('enthält keinen Server-Schlüssel', $response->body);
        self::assertStringContainsString('niemand anmelden', $response->body, 'the warning says what is at stake');

        $ende = $this->runRestoreSteps();
        self::assertTrue($ende['fertig']);
        self::assertSame('/install', $ende['weiter'] ?? null, 'the page is sent on to the access step');
        self::assertArrayNotHasKey('cron_url', $ende, 'no token exists before config.php is written');
        self::assertFileDoesNotExist($this->paths()->configFile(), 'config.php waits for the access step');
        self::assertSame(InstallController::PHASE_ZUGANG, $_SESSION['install_restore']['phase'] ?? null);
        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: [], 'temporary files are gone already');

        $seite = $this->controller()->form(new Request(HttpMethod::Get, '/install'));
        self::assertInstanceOf(Response::class, $seite);
        self::assertStringContainsString('Zugang zur wiederhergestellten Installation', $seite->body);
        self::assertStringContainsString('action="/install/serverschluessel"', $seite->body);
        self::assertStringContainsString('action="/install/zugang"', $seite->body);

        return $sicherung;
    }

    /**
     * The chosen path of issue #154: a key-less backup no longer ends in an
     * installation nobody can enter. The recovery key authorises, the
     * password identifies the account, and afterwards the login finds it
     * under the NEW server key.
     */
    public function testARestoreWithoutKeyRelinksTheAdminWithTheRecoveryKey(): void
    {
        $sicherung = $this->bisZumZugangsschritt();

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => 'neu@verein.example',
            'admin_name' => 'Admina Neu',
            'admin_password' => $this->validPost()['admin_password'],
        ]);

        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Wiederherstellung abgeschlossen', $response->body);
        self::assertStringContainsString('neu@verein.example', $response->body);
        self::assertStringContainsString('zweite Faktor wird beim ersten Anmelden neu eingerichtet', $response->body);
        self::assertStringContainsString('keine weiteren Benutzerkonten', $response->body);
        self::assertArrayNotHasKey('install_restore', $_SESSION);
        self::assertFileDoesNotExist($this->base . '/web/setup.php');

        $config = Config::fromFile($this->paths()->configFile());
        self::assertNotSame($sicherung['alterSchluessel'], base64_encode($config->serverKey), 'a new server key');
        self::assertStringContainsString('value="http://localhost/cron?token=' . $config->cronToken . '"', $response->body, 'issue #244');
        $neu = new ServerCrypto($config->serverKey);

        // The login finds the account under the new key, and reads it.
        $user = new UserRepository($this->pdo())->findByEmailBlindIndex($neu->blindIndex()->forValue('user.email', 'neu@verein.example'));
        self::assertNotNull($user);
        self::assertSame($sicherung['userId'], $user->id);
        self::assertSame('neu@verein.example', $neu->decrypt($user->emailEnc));
        self::assertSame('Admina Neu', $neu->decrypt($user->displayNameEnc));
        self::assertTrue(password_verify($this->validPost()['admin_password'], $user->passwordHash), 'the password is untouched');

        // The authenticator secret was ciphertext under the lost key: gone,
        // the first login sets 2FA up again (mfa_required stays).
        self::assertNull(new MfaTotpRepository($this->pdo())->forUser($user->id));
        self::assertNull($user->mfaMethod);
        self::assertTrue($user->mfaRequired);

        // Grant and key pair survived the restore - the password still opens the vault.
        $grant = new VaultGrantRepository($this->pdo())->forUser($user->id);
        $userKey = new UserKeyRepository($this->pdo())->forUser($user->id);
        self::assertNotNull($grant);
        self::assertNotNull($userKey);
        $vault = $grant->open($userKey->unwrap($this->validPost()['admin_password']), (string) $this->pdo()->query('SELECT public_key FROM vault')->fetchColumn());
        self::assertTrue($vault->isUnlocked());

        // The queue held only ciphertext under the lost key: emptied, so the
        // cron and the admin queue page do not trip over it.
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());

        // Audited, with the account as the actor; nothing readable in the row.
        $kopf = new AuditLogRepository($this->pdo())->head();
        self::assertNotNull($kopf);
        self::assertSame(AuditAction::ZugangNeuVerknuepft->value, $kopf->action);
        self::assertSame($user->id, $kopf->userId);
        $details = $kopf->detailsEnc ?? '';
        self::assertStringNotContainsString('neu@verein.example', $details);
        self::assertStringNotContainsString($sicherung['gruppen'][0], $details);

        // The recovery key and the password are nowhere in the database.
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $tabelle) {
            foreach ($this->pdo()->query(sprintf('SELECT * FROM `%s`', (string) $tabelle))->fetchAll() as $zeile) {
                foreach ($zeile as $wert) {
                    if (is_string($wert)) {
                        self::assertStringNotContainsString($sicherung['gruppen'][6], $wert);
                        self::assertStringNotContainsString($this->validPost()['admin_password'], $wert);
                    }
                }
            }
        }
    }

    /**
     * An account without a grant (a password reset shortly before the
     * backup) gets one from the recovered vault - the same thing
     * /admin/wiederherstellen would do after a login that is impossible here.
     */
    public function testRelinkingGrantsTheVaultWhenTheAccountHadNoGrant(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        $this->pdo()->exec('DELETE FROM vault_grant');

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode('', $sicherung['gruppen']),
            'admin_email' => $this->validPost()['admin_email'],
            'admin_name' => $this->validPost()['admin_name'],
            'admin_password' => $this->validPost()['admin_password'],
        ]);

        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Der Tresor ist für das Konto freigegeben', $response->body);
        $grant = new VaultGrantRepository($this->pdo())->forUser($sicherung['userId']);
        self::assertNotNull($grant);
        $userKey = new UserKeyRepository($this->pdo())->forUser($sicherung['userId']);
        self::assertNotNull($userKey);
        self::assertTrue($grant->open($userKey->unwrap($this->validPost()['admin_password']), (string) $this->pdo()->query('SELECT public_key FROM vault')->fetchColumn())->isUnlocked());
    }

    /**
     * Two admins with the same password cannot be told apart - nothing else
     * about them is readable - so the step refuses rather than guessing
     * whose account gets the typed address.
     */
    public function testRelinkingRefusesWhenTwoAdminsShareThePassword(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        $this->pdo()->exec(
            "INSERT INTO `user` (email_enc, email_bi, display_name_enc, password_hash, status, mfa_required, created_at)
             SELECT 'x', 'y', 'z', password_hash, status, mfa_required, created_at FROM `user` WHERE id = " . $sicherung['userId'],
        );
        $zweiteId = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec(sprintf(
            'INSERT INTO user_role (user_id, role_id) SELECT %d, role_id FROM user_role WHERE user_id = %d',
            $zweiteId,
            $sicherung['userId'],
        ));

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => 'neu@verein.example',
            'admin_name' => 'Admina Neu',
            'admin_password' => $this->validPost()['admin_password'],
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('mehreren Admin-Konten', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    /**
     * config.php is written inside the re-link's transaction: when the
     * write fails, the account is not left readable only under a key that
     * exists nowhere, and the old config.php of "Weg 1" still fits.
     */
    public function testAFailedConfigWriteRollsTheRelinkBack(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        // A directory where config.php should be: ConfigWriter cannot write.
        mkdir($this->paths()->configFile(), 0775, true);
        $vorher = $this->pdo()->query('SELECT email_bi, display_name_enc FROM `user`')->fetch();

        // file_put_contents() on a directory raises a warning before
        // ConfigWriter turns it into the exception - swallowed here, it is
        // the failure under test, not a defect.
        set_error_handler(static fn(): bool => true, \E_WARNING);
        try {
            $this->postAccess('/install/zugang', [
                'schluessel' => implode(' ', $sicherung['gruppen']),
                'admin_email' => 'neu@verein.example',
                'admin_name' => 'Admina Neu',
                'admin_password' => $this->validPost()['admin_password'],
            ]);
            self::fail('an unwritable config.php has to surface');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('config.php', $e->getMessage());
        } finally {
            restore_error_handler();
            rmdir($this->paths()->configFile());
        }

        self::assertSame($vorher, $this->pdo()->query('SELECT email_bi, display_name_enc FROM `user`')->fetch(), 'rolled back');
        self::assertNotNull(new MfaTotpRepository($this->pdo())->forUser($sicherung['userId']), 'rolled back');
        self::assertSame(InstallController::PHASE_ZUGANG, $_SESSION['install_restore']['phase'] ?? null, 'the step stays open');

        $richtig = $this->postAccess('/install/serverschluessel', ['server_key' => $sicherung['alterSchluessel']]);
        self::assertSame(200, $richtig->status, 'Weg 1 still works afterwards');
    }

    public function testRelinkingRefusesAWrongPasswordAndWritesNothing(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        $vorher = $this->pdo()->query('SELECT email_bi, display_name_enc FROM `user`')->fetch();

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => 'neu@verein.example',
            'admin_name' => 'Admina Neu',
            'admin_password' => 'ganz-und-gar-das-falsche-passwort',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('kein aktives Admin-Konto', $response->body);
        self::assertStringContainsString('value="neu@verein.example"', $response->body, 'address and name are shown again');
        self::assertStringNotContainsString($sicherung['gruppen'][0], $response->body, 'the key is not echoed');
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertSame($vorher, $this->pdo()->query('SELECT email_bi, display_name_enc FROM `user`')->fetch(), 'the row is untouched');
        self::assertSame(InstallController::PHASE_ZUGANG, $_SESSION['install_restore']['phase'] ?? null, 'the step stays open');
    }

    public function testRelinkingRefusesTheRecoveryKeyOfAnotherVaultOrAMistypedOne(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        $felder = [
            'admin_email' => $this->validPost()['admin_email'],
            'admin_name' => $this->validPost()['admin_name'],
            'admin_password' => $this->validPost()['admin_password'],
        ];

        $fremd = $this->postAccess('/install/zugang', [...$felder, 'schluessel' => RecoveryKey::forVault(Vault::create())->formatted()]);
        self::assertSame(422, $fremd->status);
        self::assertStringContainsString('nicht zu dieser Installation', $fremd->body);

        $gruppen = $sicherung['gruppen'];
        $gruppen[3] = strrev($gruppen[3]) === $gruppen[3] ? 'AAAAAAAA' : strrev($gruppen[3]);
        $vertippt = $this->postAccess('/install/zugang', [...$felder, 'schluessel' => implode(' ', $gruppen)]);
        self::assertSame(422, $vertippt->status);
        self::assertStringContainsString('falsch eingegeben', $vertippt->body);

        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    /**
     * The other alternative: the admin kept a copy of the old config.php.
     * Its key is taken over - and only because it really opens the data.
     */
    public function testARestoreWithoutKeyAcceptsTheOldServerKeyWhenItOpensTheData(): void
    {
        $sicherung = $this->bisZumZugangsschritt();

        $falsch = $this->postAccess('/install/serverschluessel', ['server_key' => ConfigWriter::generateServerKey()]);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('passt nicht zu den eingespielten Daten', $falsch->body);
        self::assertFileDoesNotExist($this->paths()->configFile());

        $unsinn = $this->postAccess('/install/serverschluessel', ['server_key' => 'kein-schluessel']);
        self::assertSame(422, $unsinn->status);
        self::assertStringContainsString('kein Server-Schlüssel', $unsinn->body);

        $leer = $this->postAccess('/install/serverschluessel', []);
        self::assertSame(422, $leer->status);

        $richtig = $this->postAccess('/install/serverschluessel', ['server_key' => $sicherung['alterSchluessel']]);
        self::assertSame(200, $richtig->status, $richtig->body);
        self::assertStringContainsString('Wiederherstellung abgeschlossen', $richtig->body);
        self::assertStringNotContainsString('neu verknüpft', $richtig->body);

        $config = require $this->paths()->configFile();
        self::assertSame($sicherung['alterSchluessel'], $config['server_key'], 'the old key is kept');
        self::assertStringContainsString('value="http://localhost/cron?token=' . $config['cron_token'] . '"', $richtig->body, 'issue #244');
        self::assertStringContainsString('Cronjob aktualisieren', $richtig->body);
        $alt = new ServerCrypto((string) base64_decode($sicherung['alterSchluessel'], true));
        $user = new UserRepository($this->pdo())->findByEmailBlindIndex($alt->blindIndex()->forValue('user.email', $this->validPost()['admin_email']));
        self::assertNotNull($user, 'the login finds the account as before');
        self::assertNotNull(new MfaTotpRepository($this->pdo())->forUser($user->id), 'nothing was reset');
        self::assertArrayNotHasKey('install_restore', $_SESSION);
    }

    /**
     * The config.php itself may be uploaded; it is read for its key with a
     * regular expression and never executed.
     */
    public function testTheOldConfigCanBeUploadedAndIsNotExecuted(): void
    {
        $sicherung = $this->bisZumZugangsschritt();
        $marker = $this->base . '/ausgefuehrt.txt';
        $datei = $this->base . '/alte_config.php';
        file_put_contents($datei, sprintf(
            "<?php\nfile_put_contents('%s', 'x');\nreturn ['server_key' => '%s', 'cron_token' => 'alt'];\n",
            $marker,
            $sicherung['alterSchluessel'],
        ));

        $session = new Session();
        $session->start();
        $response = $this->controller()->serverKey(new Request(
            method: HttpMethod::Post,
            path: '/install/serverschluessel',
            post: ['_csrf' => $session->csrfToken()],
            files: ['config' => ['error' => \UPLOAD_ERR_OK, 'tmp_name' => $datei, 'name' => 'config.php']],
        ));
        self::assertInstanceOf(Response::class, $response);

        self::assertSame(200, $response->status, $response->body);
        self::assertFileDoesNotExist($marker, 'the uploaded config.php was never executed');
        self::assertSame($sicherung['alterSchluessel'], (require $this->paths()->configFile())['server_key']);
        self::assertNotSame('alt', (require $this->paths()->configFile())['cron_token'], 'only the key is taken over');
    }

    /**
     * While the access step is open, the installer is still an installer:
     * the form submits again show the step, the step endpoint is closed,
     * and "Neu beginnen" leads back to the form.
     */
    public function testThePendingAccessStepIsShownAgainAndCanBeAbandoned(): void
    {
        $this->bisZumZugangsschritt();

        $erneut = $this->submit($this->validPost());
        self::assertSame(200, $erneut->status);
        self::assertStringContainsString('Zugang zur wiederhergestellten Installation', $erneut->body);
        self::assertSame(1, $this->userCount(), 'no second installation was started');

        $session = new Session();
        $session->start();
        $schritt = $this->controller()->restoreStep(new Request(
            method: HttpMethod::Post,
            path: '/install/wiederherstellen',
            headers: ['x-csrf-token' => $session->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $schritt);
        self::assertSame(409, $schritt->status, 'the dump is through; there is nothing to step');

        $neu = $this->restart();
        self::assertSame(200, $neu->status);
        self::assertStringContainsString('Datenbankname', $neu->body);
        self::assertArrayNotHasKey('install_restore', $_SESSION);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testTheAccessEndpointsNeedCsrfAndAPendingRestore(): void
    {
        foreach (['access', 'serverKey'] as $methode) {
            $ohneToken = $this->controller()->{$methode}(new Request(HttpMethod::Post, '/install/zugang'));
            self::assertInstanceOf(Response::class, $ohneToken);
            self::assertSame(403, $ohneToken->status);

            $session = new Session();
            $session->start();
            unset($_SESSION['install_restore']);
            $nichtAktiv = $this->controller()->{$methode}(new Request(
                method: HttpMethod::Post,
                path: '/install/zugang',
                post: ['_csrf' => $session->csrfToken(), 'server_key' => 'x', 'schluessel' => 'x'],
            ));
            self::assertInstanceOf(Response::class, $nichtAktiv);
            self::assertSame(409, $nichtAktiv->status);
        }
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    // ------------------------------------------- vault anchor (issue #240)

    private function tresorUnter(string $serverKeyBase64): VaultRepository
    {
        return new VaultRepository($this->pdo(), new ServerCrypto((string) base64_decode($serverKeyBase64, true))->tresorAnker());
    }

    /** What an installation from before migration 047 would have backed up. */
    private function schemaVorDemAnker(): void
    {
        $version = null;
        foreach (Migration::discover($this->paths()->migrationsDir()) as $migration) {
            if (Erstbeglaubigung::betrifft($migration)) {
                $version = $migration->version;
            }
        }
        self::assertNotNull($version);
        $this->pdo()->exec('ALTER TABLE vault DROP COLUMN public_key_mac');
        $this->pdo()->prepare('DELETE FROM schema_version WHERE version >= ?')->execute([$version]);
        // Migrations after 047 (other issues) are in the dump already; the
        // restore must apply 047 alone, as from a release that ended there.
        foreach (Migration::discover($this->paths()->migrationsDir()) as $migration) {
            if ($migration->version > $version) {
                unlink($migration->path);
            }
        }
    }

    public function testAFreshInstallAnchorsTheVaultKeyWithItsServerKey(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        $this->confirmKey($this->extractGroups($this->submit($this->validPost())->body)[6]);

        $schluessel = (string) (require $this->paths()->configFile())['server_key'];
        self::assertNotNull($this->tresorUnter($schluessel)->current());
        self::assertFileExists($this->paths()->vaultAnchorFlagFile(), 'no first use on trust after this');

        $this->expectException(TresorAnkerException::class);
        $this->tresorUnter(ConfigWriter::generateServerKey())->current();
    }

    /**
     * Restore without the old server key, alternative 2: the MAC in the dump
     * was made with the old key - the recovery key proves the public key and
     * it is anchored again, under the NEW key.
     */
    public function testRelinkingAnchorsTheVaultKeyUnderTheNewServerKey(): void
    {
        $sicherung = $this->bisZumZugangsschritt();

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => $this->validPost()['admin_email'],
            'admin_name' => $this->validPost()['admin_name'],
            'admin_password' => $this->validPost()['admin_password'],
        ]);
        self::assertSame(200, $response->status, $response->body);

        $neu = (string) (require $this->paths()->configFile())['server_key'];
        self::assertNotNull($this->tresorUnter($neu)->current(), 'anchored under the new key');
        self::assertSame([1 => true], $this->tresorUnter($neu)->ankerStand());
        self::assertSame([1 => false], $this->tresorUnter($sicherung['alterSchluessel'])->ankerStand(), 'the old MAC is gone');
        $aktionen = $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains(AuditAction::TresorAnkerGesetzt->value, $aktionen);
    }

    /** Alternative 1, the old server key: the MAC from the dump fits as it is. */
    public function testTheOldServerKeyKeepsTheAnchorOfTheBackup(): void
    {
        $sicherung = $this->bisZumZugangsschritt();

        self::assertSame(200, $this->postAccess('/install/serverschluessel', ['server_key' => $sicherung['alterSchluessel']])->status);

        self::assertNotNull($this->tresorUnter($sicherung['alterSchluessel'])->current());
        $aktionen = $this->pdo()->query('SELECT action FROM audit_log')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains(AuditAction::TresorAnkerGesetzt->value, $aktionen, 'nothing to anchor anew');
    }

    /**
     * Finding H-05: a backup whose `vault.public_key` was swapped for
     * somebody else's. The recovery key does not fit it, and restored with
     * the genuine server key the swapped key fails its anchor - nothing is
     * sealed to it.
     */
    public function testADoctoredVaultKeyInABackupIsNeverUsedAfterTheRestore(): void
    {
        $fremd = Vault::create();
        $sicherung = $this->bisZumZugangsschritt(function () use ($fremd): void {
            $stmt = $this->pdo()->prepare('UPDATE vault SET public_key = ?');
            $stmt->bindValue(1, $fremd->publicKey(), \PDO::PARAM_LOB);
            $stmt->execute();
        });

        $zugang = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => $this->validPost()['admin_email'],
            'admin_name' => $this->validPost()['admin_name'],
            'admin_password' => $this->validPost()['admin_password'],
        ]);
        self::assertSame(422, $zugang->status, 'the recovery key does not fit the swapped key');

        self::assertSame(200, $this->postAccess('/install/serverschluessel', ['server_key' => $sicherung['alterSchluessel']])->status);
        self::assertSame([1 => false], $this->tresorUnter($sicherung['alterSchluessel'])->ankerStand());

        $this->expectException(TresorAnkerException::class);
        $this->tresorUnter($sicherung['alterSchluessel'])->current();
    }

    private function schluesselTauschen(Vault $fremd): void
    {
        $stmt = $this->pdo()->prepare('UPDATE vault SET public_key = ?');
        $stmt->bindValue(1, $fremd->publicKey(), \PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * Review finding on #240 (H-05): a doctored backup that pretends to come
     * from before migration 047 - no MAC column, lower schema_version, a key
     * of the attacker's. Restored with the genuine server key, its key is
     * NOT anchored: a restore never anchors on trust.
     */
    public function testADoctoredBackupFromBeforeTheAnchorIsNeverAnchoredOnTrust(): void
    {
        $fremd = Vault::create();
        $sicherung = $this->bisZumZugangsschritt(function () use ($fremd): void {
            $this->schluesselTauschen($fremd);
            $this->schemaVorDemAnker();
        });

        self::assertSame(200, $this->postAccess('/install/serverschluessel', ['server_key' => $sicherung['alterSchluessel']])->status);

        self::assertSame([1 => false], $this->tresorUnter($sicherung['alterSchluessel'])->ankerStand());
        self::assertFileExists($this->paths()->vaultAnchorFlagFile(), 'and no later update anchors it on trust either');
    }

    /** The same with the server key inside the backup. */
    public function testADoctoredBackupWithConfigFromBeforeTheAnchorIsNeverAnchoredOnTrust(): void
    {
        $fremd = Vault::create();
        $sicherung = $this->installationOhneSchluesselGesichert(function () use ($fremd): void {
            $this->schluesselTauschen($fremd);
            $this->schemaVorDemAnker();
        }, mitConfig: true);

        self::assertSame(200, $this->submitRestore($sicherung['zip'])->status);
        self::assertTrue($this->runRestoreSteps()['fertig']);

        self::assertSame($sicherung['alterSchluessel'], (require $this->paths()->configFile())['server_key']);
        self::assertSame([1 => false], $this->tresorUnter($sicherung['alterSchluessel'])->ankerStand());
    }

    /**
     * A genuine backup from before 047 is not anchored either - it waits for
     * the recovery key; alternative 2 of the access step brings it, and
     * anchors the key it proves.
     */
    public function testABackupFromBeforeTheAnchorIsAnchoredByTheRecoveryKeyOnly(): void
    {
        $sicherung = $this->bisZumZugangsschritt($this->schemaVorDemAnker(...));

        $response = $this->postAccess('/install/zugang', [
            'schluessel' => implode(' ', $sicherung['gruppen']),
            'admin_email' => $this->validPost()['admin_email'],
            'admin_name' => $this->validPost()['admin_name'],
            'admin_password' => $this->validPost()['admin_password'],
        ]);
        self::assertSame(200, $response->status, $response->body);

        $neu = (string) (require $this->paths()->configFile())['server_key'];
        self::assertNotNull($this->tresorUnter($neu)->current());
    }

    /** A current backup restored the same way keeps its MAC - nothing is taken over on trust. */
    public function testACurrentBackupIsNotAnchoredAgainOnRestore(): void
    {
        $fremd = Vault::create();
        $sicherung = $this->installationOhneSchluesselGesichert(fn() => $this->schluesselTauschen($fremd), mitConfig: true);

        self::assertSame(200, $this->submitRestore($sicherung['zip'])->status);
        self::assertTrue($this->runRestoreSteps()['fertig']);

        self::assertSame([1 => false], $this->tresorUnter($sicherung['alterSchluessel'])->ankerStand());
    }

    public function testAZipWithoutDumpIsRejectedAndNothingIsWritten(): void
    {
        $zip = $this->base . '/kaputt.zip';
        $archiv = new \ZipArchive();
        $archiv->open($zip, \ZipArchive::CREATE);
        $archiv->addFromString('etwas.txt', 'x');
        $archiv->close();

        $response = $this->submitRestore($zip);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('dump.sql fehlt', $response->body);
        self::assertFileDoesNotExist($this->paths()->configFile());
        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: []);
    }

    /**
     * The path in $_FILES is opened as a ZIP, so a path the client made up
     * must never get that far.
     */
    public function testAPathThatIsNoRealUploadIsRefused(): void
    {
        $zip = $this->base . '/irgendwo.zip';
        file_put_contents($zip, 'egal');

        $response = $this->submitRestore($zip, echterUploadTest: true);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Backup-ZIP hochladen', $response->body);
    }

    public function testRestoreWithoutAFileAsksForOne(): void
    {
        $session = new Session();
        $session->start();

        $response = $this->controller()->submit(new Request(
            method: HttpMethod::Post,
            path: '/install',
            post: [...$this->validPost(), 'modus' => 'restore', '_csrf' => $session->csrfToken()],
        ));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(422, $response->status);
        self::assertFileDoesNotExist($this->paths()->configFile());
    }

    public function testTheStepEndpointNeedsCsrfAndAnActiveRestore(): void
    {
        $ohneToken = $this->controller()->restoreStep(new Request(HttpMethod::Post, '/install/wiederherstellen'));
        self::assertInstanceOf(Response::class, $ohneToken);
        self::assertSame(403, $ohneToken->status);

        $session = new Session();
        $session->start();
        unset($_SESSION['install_restore']);
        $nichtAktiv = $this->controller()->restoreStep(new Request(
            method: HttpMethod::Post,
            path: '/install/wiederherstellen',
            headers: ['x-csrf-token' => $session->csrfToken()],
        ));
        self::assertInstanceOf(Response::class, $nichtAktiv);
        self::assertSame(409, $nichtAktiv->status);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
