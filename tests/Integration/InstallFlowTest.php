<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Config\Paths;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Installer\InstallController;
use App\Repository\SettingRepository;
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

    private function controller(): InstallController
    {
        return new InstallController(
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            $this->paths(),
            new Session(),
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
