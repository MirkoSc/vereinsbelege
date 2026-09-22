<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Config\Paths;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Installer\ConfigWriter;
use App\Installer\InstallController;
use App\Repository\SettingRepository;
use App\Repository\UserKeyRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Backup\BackupService;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Update\UpdateService;
use App\Tests\Support\DatabaseTestCase;
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

        file_put_contents($this->base . '/web/setup.php', '<?php // bootstrap installer');
    }

    protected function tearDown(): void
    {
        // The first-admin/vault step chain keeps its state in $_SESSION
        // (App\Installer\InstallController::submit()) across the whole
        // PHPUnit process, the same reason the restore tests reset
        // install_restore explicitly - a half-finished setup left behind by
        // one test must not leak into the next one's submit() call.
        unset($_SESSION['install_admin'], $_SESSION['install_restore']);
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
    private function controller(bool $echterUploadTest = false): InstallController
    {
        return new InstallController(
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            $this->paths(),
            new Session(),
            $echterUploadTest ? null : static fn(string $pfad): bool => is_file($pfad),
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

    private function confirmKey(string $letzteGruppe): Response
    {
        $session = new Session();
        $session->start();

        $response = $this->controller()->confirmKey(new Request(
            method: HttpMethod::Post,
            path: '/install/schluessel',
            post: ['_csrf' => $session->csrfToken(), 'schluessel_letzte_gruppe' => $letzteGruppe],
        ));
        self::assertInstanceOf(Response::class, $response);

        return $response;
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
    private function makeBackup(bool $mitConfig): string
    {
        $quelle = $this->base . '/quelle_config.php';
        /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
        $db = self::configData()['db'];
        ConfigWriter::write($quelle, $db);

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

        self::assertSame([], glob($this->paths()->varDir() . '/install_restore_*') ?: [], 'temporary dump removed');
        self::assertFileDoesNotExist($this->base . '/web/setup.php');
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

    public function testARestoreWithoutConfigInTheBackupGetsANewServerKey(): void
    {
        new Migrator($this->pdo(), $this->paths()->migrationsDir())->migrate();
        $zip = $this->makeBackup(mitConfig: false);
        $alterSchluessel = (require $this->base . '/quelle_config.php')['server_key'];
        $this->wipeDatabase();

        $response = $this->submitRestore($zip);
        self::assertStringContainsString('keinen Server-Schlüssel', $response->body);

        $this->runRestoreSteps();

        $config = require $this->paths()->configFile();
        self::assertNotSame($alterSchluessel, $config['server_key']);
        self::assertSame(32, strlen((string) base64_decode($config['server_key'], true)));
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
