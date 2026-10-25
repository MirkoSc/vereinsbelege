<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupService;
use App\Service\MaintenanceMode;
use App\Service\Migration\Migrator;
use App\Service\Update\DenyHtaccess;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSignatur;
use App\Service\Update\ReleaseSwitcher;
use App\Service\Update\UpdateService;
use App\Tests\Support\DatabaseTestCase;

/**
 * The update step chain against a temporary installation layout and the
 * real database (the channel setting and the migrate step both need one).
 *
 * No network: the release is a ZIP on disk, served through the downloader's
 * injected HTTP and download closures under the GitHub URLs the real
 * release carries (the downloader refuses any other host, issue #241).
 *
 * What this really guards is the property the chain lives by: every step is
 * a separate short request and may be repeated, so the state has to survive
 * in shared/update_state.json rather than in the object - which is why each
 * step below runs on a freshly built service.
 */
final class UpdateChainTest extends DatabaseTestCase
{
    private const string DOWNLOAD = 'https://github.com/MirkoSc/vereinsbelege/releases/download/';

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
    private function service(
        array $antworten,
        string $version = '1.0.0',
        int $dumpBytes = BackupErstellung::DUMP_BYTES,
        float $backupSekunden = UpdateService::BACKUP_SEKUNDEN,
        ?\Closure $rollbackHindernis = null,
        string $publicKey = '',
    ): UpdateService {
        $downloader = new ReleaseDownloader(
            function (string $url) use ($antworten): string {
                if (!isset($antworten[$url])) {
                    throw new \RuntimeException('HTTP 404 für ' . $url);
                }

                return $antworten[$url];
            },
            // the ZIP's URL answers with the path of the local file
            function (string $url, string $ziel) use ($antworten): void {
                if (!isset($antworten[$url])) {
                    throw new \RuntimeException('Download fehlgeschlagen (HTTP 404)');
                }
                copy($antworten[$url], $ziel);
            },
        );

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
            backups: new BackupErstellung(
                new BackupService(
                    $this->pdo(),
                    $this->paths()->backupDir(),
                    $this->paths()->configFile(),
                    $version,
                    $this->paths()->blobDir(),
                ),
                $this->pdo(),
                $this->paths()->backupDir(),
                $this->paths()->configFile(),
                null,
                $dumpBytes,
            ),
            backupSekunden: $backupSekunden,
            rollbackHindernis: $rollbackHindernis,
            publicKey: $publicKey,
        );
    }

    /**
     * @param string|null $secret signs checksums.txt and publishes
     *        checksums.txt.sig when given
     * @return array<string, string>
     */
    private function githubAnswers(string $version, string $zipFile, ?string $secret = null): array
    {
        $basis = self::DOWNLOAD . 'v' . $version . '/';
        $assets = [
            ['name' => 'vereinsbelege-v' . $version . '.zip', 'browser_download_url' => $basis . basename($zipFile)],
            ['name' => 'checksums.txt', 'browser_download_url' => $basis . 'checksums.txt'],
        ];
        $liste = hash_file('sha256', $zipFile) . '  ' . basename($zipFile) . "\n"
            . hash_file('sha256', $zipFile) . "  release/VERSION\n";
        $antworten = [
            $basis . basename($zipFile) => $zipFile,
            $basis . 'checksums.txt' => $liste,
        ];
        if ($secret !== null) {
            $assets[] = ['name' => 'checksums.txt.sig', 'browser_download_url' => $basis . 'checksums.txt.sig'];
            $antworten[$basis . 'checksums.txt.sig'] = ReleaseSignatur::signiere($liste, $secret) . "\n";
        }

        return [
            'https://api.github.com/repos/MirkoSc/vereinsbelege/releases/latest' => json_encode([
                'tag_name' => 'v' . $version,
                'assets' => $assets,
            ], JSON_THROW_ON_ERROR),
            ...$antworten,
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
     * release is switched. It must not carry the server key - and not the
     * blobs either: this is one short request, and an update never touches
     * shared/var/blobs/ (docs/spec/06-betrieb.md section 2).
     */
    public function testTheBackupStepRunsBeforeTheSwitchAndLeavesTheConfigOut(): void
    {
        $blobDir = $this->paths()->blobDir() . '/ab';
        mkdir($blobDir, 0775, true);
        file_put_contents($blobDir . '/' . str_repeat('ab', 16), 'Chiffrat');

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
        self::assertFalse(
            $archiv->getFromName('blobs/ab/' . str_repeat('ab', 16)),
            'the automatic backup never carries the blobs',
        );
        $archiv->close();
    }

    /**
     * Issue #208: with storage backend `db` the dump takes many requests.
     * The backup step answers `weiter` until its chain is through and stays
     * the open step meanwhile; repeated, it finishes one backup.
     */
    public function testTheBackupStepRepeatsUntilItsChainIsThrough(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();

        $aufrufe = 0;
        do {
            // One part per call: a byte budget of 1 and no time budget.
            $state = $this->service($antworten, dumpBytes: 1, backupSekunden: 0.0)->backup();
            self::assertNull($state->fehler);
            $aufrufe++;
            self::assertLessThan(10000, $aufrufe, 'the step ends');
            if ($state->weiter) {
                self::assertSame('extract', $state->abgeschlossenerSchritt, 'not through yet');
                self::assertSame([], glob($this->paths()->backupDir() . '/backup_*.zip') ?: []);
            }
        } while ($state->weiter);

        self::assertGreaterThan(2, $aufrufe, 'the dump took several calls');
        self::assertSame('backup', $state->abgeschlossenerSchritt);
        self::assertStringStartsWith('Backup erstellt: ', $state->meldungen[count($state->meldungen) - 1]);
        self::assertFalse($this->service($antworten)->state()?->weiter, 'kept as the state file says');
        $backups = glob($this->paths()->backupDir() . '/backup_*.zip') ?: [];
        self::assertCount(1, $backups);
        $archiv = new \ZipArchive();
        self::assertTrue($archiv->open($backups[0], \ZipArchive::CHECKCONS));
        self::assertStringEndsWith("SET FOREIGN_KEY_CHECKS = 1;\n", (string) $archiv->getFromName('dump.sql'));
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
     * The list of the release travels WITH the release directory (issue
     * #241, follow-up of #79): after a rollback the list in current/ still
     * belongs to current/.
     */
    public function testTheListTravelsWithTheRelease(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['http://localhost/'] = '<!DOCTYPE html>';
        file_put_contents($this->base . '/current/checksums.txt', "liste von 1.0.0\n");

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        self::assertNull($this->service($antworten)->extract()->fehler);
        self::assertSame(
            $antworten[self::DOWNLOAD . 'v1.1.0/checksums.txt'],
            file_get_contents($this->base . '/releases/v1.1.0/checksums.txt'),
        );
        self::assertFileDoesNotExist($this->base . '/releases/v1.1.0/checksums.txt.sig', 'unsigned release');

        $this->service($antworten)->switchRelease();
        self::assertStringContainsString(basename($zip), (string) file_get_contents($this->base . '/current/checksums.txt'));
        self::assertFileDoesNotExist($this->paths()->releaseChecksumsFile(), 'no longer kept in shared/');

        $this->service($antworten)->rollback();
        self::assertSame("liste von 1.0.0\n", file_get_contents($this->base . '/current/checksums.txt'));
    }

    public function testTheFinishStepCleansUpTheDownload(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['http://localhost/'] = '<!DOCTYPE html>';

        foreach (['check', 'download', 'extract', 'switchRelease', 'migrate'] as $schritt) {
            self::assertNull($this->service($antworten)->{$schritt}()->fehler, $schritt);
        }
        self::assertNull($this->service($antworten)->finish('http://localhost')->fehler);

        self::assertSame([], glob($this->base . '/shared/var/update/*') ?: []);
    }

    /**
     * The update that brings #241 still downloads with the previous
     * release's code, which kept the list in shared/release_checksums.txt.
     * The finish step (first step on the new code) takes it over - only
     * when it names exactly this version.
     */
    public function testTheFinishStepTakesOverTheListOfTheOlderUpdater(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['http://localhost/'] = '<!DOCTYPE html>';
        foreach (['check', 'download', 'extract', 'switchRelease'] as $schritt) {
            $this->service($antworten)->{$schritt}();
        }
        // as the previous release's updater left it
        unlink($this->base . '/current/checksums.txt');
        $liste = $antworten[self::DOWNLOAD . 'v1.1.0/checksums.txt'];
        file_put_contents($this->paths()->releaseChecksumsFile(), $liste);

        self::assertNull($this->service($antworten, '1.1.0')->finish('http://localhost')->fehler);
        self::assertSame($liste, file_get_contents($this->base . '/current/checksums.txt'));
    }

    public function testTheFinishStepIgnoresAListOfAnotherVersion(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten['http://localhost/'] = '<!DOCTYPE html>';
        foreach (['check', 'download', 'extract', 'switchRelease'] as $schritt) {
            $this->service($antworten)->{$schritt}();
        }
        unlink($this->base . '/current/checksums.txt');
        file_put_contents($this->paths()->releaseChecksumsFile(), str_repeat('1', 64) . "  vereinsbelege-v1.0.0.zip\n");

        self::assertNull($this->service($antworten, '1.1.0')->finish('http://localhost')->fehler);
        self::assertFileDoesNotExist($this->base . '/current/checksums.txt');
    }

    /**
     * N-02 before the maintainer has fixed a key: an unsigned release still
     * installs (an older release has no signature, a build without the
     * secret neither) - with a warning in the log.
     */
    public function testWithoutAKeyAnUnsignedReleaseInstallsWithAWarning(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $state = $this->service($antworten)->download();

        self::assertNull($state->fehler);
        self::assertStringContainsString('nicht signiert', $state->meldungen[count($state->meldungen) - 1]);
        self::assertNull($this->service($antworten)->extract()->fehler);
    }

    public function testASignedReleaseIsConfirmedAndKeepsItsSignature(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip, $paar['secret']);

        $this->service($antworten, publicKey: $paar['public'])->check();
        $state = $this->service($antworten, publicKey: $paar['public'])->download();

        self::assertNull($state->fehler);
        self::assertStringContainsString('Signatur bestätigt', $state->meldungen[count($state->meldungen) - 1]);
        self::assertNull($this->service($antworten, publicKey: $paar['public'])->extract()->fehler);
        self::assertSame(
            $antworten[self::DOWNLOAD . 'v1.1.0/checksums.txt.sig'],
            file_get_contents($this->base . '/releases/v1.1.0/checksums.txt.sig'),
        );
    }

    public function testOnceAKeyIsFixedAnUnsignedReleaseIsRefused(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten, publicKey: $paar['public'])->check();
        $state = $this->service($antworten, publicKey: $paar['public'])->download();

        self::assertStringContainsString('nicht signiert', (string) $state->fehler);
        self::assertSame('check', $state->abgeschlossenerSchritt);
    }

    /**
     * A forged release with a correct checksums.txt for its own ZIP - what
     * the SHA-256 alone cannot catch.
     */
    public function testAReleaseSignedWithAnotherKeyIsRefused(): void
    {
        $echt = ReleaseSignatur::erzeugeSchluesselpaar();
        $fremd = ReleaseSignatur::erzeugeSchluesselpaar();
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip, $fremd['secret']);

        $this->service($antworten, publicKey: $echt['public'])->check();
        $state = $this->service($antworten, publicKey: $echt['public'])->download();

        self::assertStringContainsString('Signatur des Releases ist ungültig', (string) $state->fehler);
    }

    /**
     * List and ZIP wait in shared/var between download and extract - FTP
     * access could swap them there. The extract step checks again.
     */
    public function testAListSwappedAfterTheDownloadIsCaughtBeforeUnpacking(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip, $paar['secret']);
        $this->service($antworten, publicKey: $paar['public'])->check();
        $this->service($antworten, publicKey: $paar['public'])->download();

        file_put_contents($this->base . '/shared/var/update/liste-v1.1.0/checksums.txt', "\n", FILE_APPEND);
        $state = $this->service($antworten, publicKey: $paar['public'])->extract();

        self::assertStringContainsString('Signatur', (string) $state->fehler);
        self::assertDirectoryDoesNotExist($this->base . '/releases/v1.1.0');
    }

    public function testAZipSwappedAfterTheDownloadIsCaughtBeforeUnpacking(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $this->service($antworten)->check();
        $this->service($antworten)->download();

        file_put_contents($this->base . '/shared/var/update/vereinsbelege-v1.1.0.zip', 'anders', FILE_APPEND);
        $state = $this->service($antworten)->extract();

        self::assertStringContainsString('Prüfsummen-Fehler', (string) $state->fehler);
    }

    /**
     * N-18: shared/update_state.json is writable over FTP; a URL changed
     * there must not make the updater download from elsewhere.
     */
    public function testATamperedDownloadUrlInTheStateIsRefused(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $this->service($antworten)->check();

        $datei = $this->paths()->updateStateFile();
        $stand = (array) json_decode((string) file_get_contents($datei), true);
        $stand['zip_url'] = 'https://evil.example/vereinsbelege-v1.1.0.zip';
        file_put_contents($datei, json_encode($stand, JSON_THROW_ON_ERROR));

        $state = $this->service($antworten)->download();

        self::assertStringContainsString('nicht erlaubt', (string) $state->fehler);
        self::assertFileDoesNotExist($this->base . '/shared/var/update/vereinsbelege-v1.1.0.zip');
    }

    /**
     * Target and URLs live in update_state.json: pointing them at an older,
     * genuinely published (and signed) release must not downgrade the
     * installation to a known hole.
     */
    public function testATamperedStateCannotDowngrade(): void
    {
        $paar = ReleaseSignatur::erzeugeSchluesselpaar();
        $neu = $this->buildReleaseZip('1.1.0');
        $alt = $this->buildReleaseZip('0.9.0');
        $antworten = [
            ...$this->githubAnswers('1.1.0', $neu, $paar['secret']),
            ...array_slice($this->githubAnswers('0.9.0', $alt, $paar['secret']), 1),
        ];
        $this->service($antworten, publicKey: $paar['public'])->check();

        $datei = $this->paths()->updateStateFile();
        $stand = (array) json_decode((string) file_get_contents($datei), true);
        $stand['ziel_version'] = '0.9.0';
        $stand['zip_url'] = self::DOWNLOAD . 'v0.9.0/vereinsbelege-v0.9.0.zip';
        $stand['checksums_url'] = self::DOWNLOAD . 'v0.9.0/checksums.txt';
        $stand['signatur_url'] = self::DOWNLOAD . 'v0.9.0/checksums.txt.sig';
        file_put_contents($datei, json_encode($stand, JSON_THROW_ON_ERROR));

        $state = $this->service($antworten, publicKey: $paar['public'])->download();

        self::assertStringContainsString('nicht neuer', (string) $state->fehler);
    }

    /**
     * A signed list of another release does not vouch for this one.
     */
    public function testTheListMustNameTheTargetVersion(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten[self::DOWNLOAD . 'v1.1.0/checksums.txt'] =
            hash_file('sha256', $zip) . "  vereinsbelege-v1.2.0.zip\n";

        $this->service($antworten)->check();
        $state = $this->service($antworten)->download();

        self::assertStringContainsString('gehört nicht zu Version 1.1.0', (string) $state->fehler);
    }

    /**
     * The state file of an update started by a release before #241 has no
     * signatur_url - the chain continues.
     */
    public function testAStateWithoutSignatureUrlStillWorks(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $this->service($antworten)->check();
        $datei = $this->paths()->updateStateFile();
        $stand = (array) json_decode((string) file_get_contents($datei), true);
        unset($stand['signatur_url']);
        file_put_contents($datei, json_encode($stand, JSON_THROW_ON_ERROR));

        self::assertNull($this->service($antworten)->download()->fehler);
    }

    public function testATamperedZipStopsTheChain(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);
        $antworten[self::DOWNLOAD . 'v1.1.0/checksums.txt'] = str_repeat('0', 64) . '  ' . basename($zip) . "\n";

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
        // Built as 9.9.9 but published under the name of 1.1.0.
        $zip = $this->buildReleaseZip('9.9.9');
        rename($zip, $this->base . '/vereinsbelege-v1.1.0.zip');
        $zip = $this->base . '/vereinsbelege-v1.1.0.zip';
        $antworten = $this->githubAnswers('1.1.0', $zip);

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
        // The previous release in this fixture predates issue #101 - it
        // comes back as current/ without a .htaccess, the rollback adds one.
        self::assertSame(DenyHtaccess::CONTENT, file_get_contents($this->base . '/current/.htaccess'));
    }

    /**
     * Issue #90: an older release reads `vault` as "newest row is the key" -
     * while a vault key rotation runs, the rollback is refused and the new
     * release stays active.
     */
    public function testRollbackIsRefusedWhileAVaultKeyRotationRuns(): void
    {
        $zip = $this->buildReleaseZip('1.1.0');
        $antworten = $this->githubAnswers('1.1.0', $zip);

        $this->service($antworten)->check();
        $this->service($antworten)->download();
        $this->service($antworten)->extract();
        $this->service($antworten)->switchRelease();

        $state = $this->service($antworten, rollbackHindernis: static fn(): string => 'Rotation erst abschließen')->rollback();

        self::assertSame('Rotation erst abschließen', $state->fehler);
        self::assertSame("1.1.0\n", file_get_contents($this->base . '/current/VERSION'));
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
