<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Backup\BackupService;
use App\Service\MaintenanceMode;
use App\Service\Migration\Migrator;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSwitcher;
use App\Service\Update\UpdateService;
use App\Tests\Support\DatabaseTestCase;

/**
 * The update step chain against a temporary installation layout and the
 * real database (the channel setting and the migrate step both need one).
 *
 * No network: the release is a ZIP on disk, served through the downloader's
 * injected HTTP closure.
 *
 * What this really guards is the property the chain lives by: every step is
 * a separate short request and may be repeated, so the state has to survive
 * in shared/update_state.json rather than in the object - which is why each
 * step below runs on a freshly built service.
 */
final class UpdateChainTest extends DatabaseTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        // The setting table the update channel lives in.
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->base = sys_get_temp_dir() . '/vb_update_' . uniqid('', true);
        mkdir($this->base . '/current', 0775, true);
        mkdir($this->base . '/releases', 0775, true);
        mkdir($this->base . '/shared', 0775, true);
        mkdir($this->base . '/web', 0775, true);
        file_put_contents($this->base . '/current/VERSION', "1.0.0\n");
        file_put_contents($this->base . '/web/index.php', "<?php // alter Shim\n");
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
     * A release ZIP that unpacks into a valid release: VERSION plus the
     * docroot templates the updater refreshes from.
     */
    private function buildReleaseZip(string $version): string
    {
        $file = $this->base . '/vereinsbelege-v' . $version . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('VERSION', $version . "\n");
        $zip->addFromString('docker/web/.htaccess', "neue CSP\n");
        $zip->addFromString('docker/web/.user.ini', "zend.exception_ignore_args = On\n");
        $zip->close();

        return $file;
    }

    /**
     * @param array<string, string> $antworten URL => body
     */
    private function service(array $antworten, string $version = '1.0.0'): UpdateService
    {
        $downloader = new ReleaseDownloader(function (string $url) use ($antworten): string {
            if (!isset($antworten[$url])) {
                throw new \RuntimeException('HTTP 404 für ' . $url);
            }

            return $antworten[$url];
        });

        return new UpdateService(
            paths: $this->paths(),
            currentVersion: $version,
            settings: new SettingRepository($this->pdo()),
            downloader: $downloader,
            switcher: new ReleaseSwitcher(
                $this->base,
                new MaintenanceMode($this->base . '/shared/maintenance.flag'),
            ),
            migrator: new Migrator($this->pdo(), $this->migrationsDir()),
            backups: new BackupService(
                $this->pdo(),
                $this->paths()->backupDir(),
                $this->paths()->configFile(),
                $version,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function githubAnswers(string $version, string $zipFile): array
    {
        return [
            'https://api.github.com/repos/MirkoSc/vereinsbelege/releases/latest' => json_encode([
                'tag_name' => 'v' . $version,
                'assets' => [
                    ['name' => 'vereinsbelege-v' . $version . '.zip', 'browser_download_url' => $zipFile],
                    ['name' => 'checksums.txt', 'browser_download_url' => 'https://example.test/checksums.txt'],
                ],
            ], JSON_THROW_ON_ERROR),
            'https://example.test/checksums.txt' =>
                hash_file('sha256', $zipFile) . '  ' . basename($zipFile) . "\n",
        ];
    }

    public function testChannelDefaultsToStableAndIsPersisted(): void
    {
        $service = $this->service([]);

        self::assertSame('stable', $service->channel());

        $service->setChannel('beta');
        self::assertSame('beta', $this->service([])->channel(), 'survives in the database, not in the object');

        $service->setChannel('etwas-anderes');
        self::assertSame('stable', $service->channel(), 'unknown values fall back, never pass through');
    }

    public function testCheckReportsWhenTheInstallationIsUpToDate(): void
    {
        $zip = $this->buildReleaseZip('1.0.0');
        $state = $this->service($this->githubAnswers('1.0.0', $zip))->check();

        self::assertTrue($state->fertig);
        self::assertNull($state->zielVersion);
        self::assertStringContainsString('ist aktuell', $state->meldungen[0]);
    }

    public function testFullChainUpdatesTheInstallation(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        // the self-test fetches the start page through the web server
        $antworten['http://localhost/'] = '<!DOCTYPE html>';

        $state = $this->service($antworten)->check();
        self::assertSame('1.1.0', $state->zielVersion);
        self::assertSame('check', $state->abgeschlossenerSchritt);

        self::assertNull($this->service($antworten)->download()->fehler);
        self::assertNull($this->service($antworten)->extract()->fehler);
        self::assertNull($this->service($antworten)->backup()->fehler);
        self::assertNull($this->service($antworten)->switchRelease()->fehler);
        self::assertNull($this->service($antworten)->migrate()->fehler);

        $letzter = $this->service($antworten)->finish('http://localhost');

        self::assertNull($letzter->fehler);
        self::assertTrue($letzter->fertig);
        self::assertSame("1.1.0\n", file_get_contents($this->base . '/current/VERSION'));
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag');
        self::assertSame(
            ReleaseSwitcher::SHIM,
            file_get_contents($this->base . '/web/index.php'),
            'the shim self-heals in the finish step',
        );
        self::assertSame(
            "neue CSP\n",
            file_get_contents($this->base . '/web/.htaccess'),
            'the docroot files travel with the release too',
        );
        self::assertDirectoryExists($this->base . '/releases/_prev', 'rollback stays possible');
    }

    /**
     * Repeating a step is the documented repair path after a lost response,
     * so no step may depend on being run exactly once.
     */
    public function testStepsAreIdempotent(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();
        $this->service($antworten)->extract();
        $this->service($antworten)->backup();
        self::assertNull($this->service($antworten)->backup()->fehler, 'a repeated backup step is harmless');
        $this->service($antworten)->switchRelease();
        $state = $this->service($antworten)->switchRelease();

        self::assertNull($state->fehler);
        self::assertSame("1.1.0\n", file_get_contents($this->base . '/current/VERSION'));
    }

    /**
     * The point of the step: a restorable copy has to exist BEFORE the
     * release is switched, and it must not carry the server key.
     */
    public function testTheBackupStepRunsBeforeTheSwitchAndLeavesTheConfigOut(): void
    {
        self::assertSame(
            ['backup', 'switch'],
            array_slice(UpdateService::STEPS, array_search('backup', UpdateService::STEPS, true), 2),
            'backup sits directly before switch',
        );

        file_put_contents($this->paths()->configFile(), "<?php return ['server_key' => 'nicht ins backup'];\n");
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();
        $state = $this->service($antworten)->backup();

        self::assertNull($state->fehler);
        self::assertSame('backup', $state->abgeschlossenerSchritt);
        self::assertSame("1.0.0\n", file_get_contents($this->base . '/current/VERSION'), 'not switched yet');

        $backups = glob($this->paths()->backupDir() . '/backup_*.zip') ?: [];
        self::assertCount(1, $backups);

        $archiv = new \ZipArchive();
        self::assertTrue($archiv->open($backups[0]));
        self::assertNotFalse($archiv->getFromName('dump.sql'));
        self::assertFalse($archiv->getFromName('config.php'), 'the automatic backup never carries the server key');
        $archiv->close();
    }

    public function testAFailingBackupStopsTheChainBeforeTheSwitch(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $this->service($antworten)->check();

        // a file where the backup directory has to be
        mkdir($this->base . '/shared/var', 0775, true);
        file_put_contents($this->paths()->backupDir(), 'im Weg');

        $state = $this->service($antworten)->backup();

        self::assertNotNull($state->fehler);
        self::assertSame("1.0.0\n", file_get_contents($this->base . '/current/VERSION'));
    }

    /**
     * The checksums file of the installed release is kept for the code
     * integrity check of the admin area (docs/spec/01-sicherheit.md §8).
     */
    public function testDownloadKeepsTheChecksumsFile(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $this->service($antworten)->download();

        self::assertStringContainsString(
            basename($zip),
            (string) file_get_contents($this->paths()->releaseChecksumsFile()),
        );
    }

    public function testATamperedZipStopsTheChain(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['https://example.test/checksums.txt'] = str_repeat('0', 64) . '  ' . basename($zip) . "\n";

        $this->service($antworten)->check();
        $state = $this->service($antworten)->download();

        self::assertNotNull($state->fehler);
        self::assertStringContainsString('Prüfsummen-Fehler', $state->fehler);
        self::assertSame(
            "1.0.0\n",
            file_get_contents($this->base . '/current/VERSION'),
            'nothing was switched',
        );
    }

    /**
     * A ZIP whose VERSION does not match the release it claims to be would
     * switch the installation to something unknown.
     */
    public function testAReleaseWithTheWrongVersionFileIsRejected(): void
    {
        $zip = $this->buildReleaseZip('9.9.9');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['https://example.test/checksums.txt'] =
            hash_file('sha256', $zip) . '  ' . basename($zip) . "\n";

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $state = $this->service($antworten)->extract();

        self::assertNotNull($state->fehler);
        self::assertStringContainsString('VERSION', $state->fehler);
    }

    /**
     * A failing self-test must put the previous shim back: a broken shim
     * takes the whole site down, /admin included, so there would be no way
     * left to trigger the rollback.
     */
    public function testAFailedSelfTestRestoresThePreviousShim(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        // no answer for http://localhost/ => the self-test fails

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();
        $this->service($antworten)->switchRelease();
        $state = $this->service($antworten)->finish('http://localhost');

        self::assertNotNull($state->fehler);
        self::assertStringContainsString('Selbsttest fehlgeschlagen', $state->fehler);
        self::assertSame("<?php // alter Shim\n", file_get_contents($this->base . '/web/index.php'));
    }

    public function testRollbackReturnsToThePreviousRelease(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();
        $this->service($antworten)->switchRelease();

        $state = $this->service($antworten)->rollback();

        self::assertNull($state->fehler);
        self::assertSame("1.0.0\n", file_get_contents($this->base . '/current/VERSION'));
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag');
    }

    public function testAStepWithoutAPreparedUpdateFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kein Update vorbereitet');

        $this->service([])->download();
    }

    public function testResetRemovesTheStateFile(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $service = $this->service($this->githubAnswers('1.1.0', $zip));

        $service->check();
        self::assertNotNull($service->state());

        $service->reset();
        self::assertNull($service->state());
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
