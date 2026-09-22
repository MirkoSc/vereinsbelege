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
use App\Service\Backup\BackupService;
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

        file_put_contents($this->base . '/web/setup.php', '<?php // bootstrap installer');
    }

    protected function tearDown(): void
    {
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
        ];
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

    public function testAFreshInstallCreatesSchemaConfigAndSetting(): void
    {
        mkdir($this->paths()->sharedDir(), 0775, true);
        file_put_contents($this->paths()->setupChannelFile(), 'beta');

        $response = $this->submit($this->validPost());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Installation abgeschlossen', $response->body);

        // config.php exists and is readable by the application
        $config = Config::fromFile($this->paths()->configFile());
        self::assertSame(self::dbName(), $config->dbName);
        self::assertNotSame('', $config->cronToken);

        // every migration applied
        $tables = $this->pdo()
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('schema_version', $tables);
        self::assertContains('setting', $tables);

        self::assertSame(
            'beta',
            new SettingRepository($this->pdo())->get(UpdateService::SETTING_CHANNEL),
            'the channel from setup.php is carried into the installation',
        );

        // setup.php is a second, unauthenticated entry point and has to go
        self::assertFileDoesNotExist($this->base . '/web/setup.php');
        self::assertFileDoesNotExist($this->paths()->setupChannelFile());
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
