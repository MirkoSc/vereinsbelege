<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;

/**
 * Update step chain (docs/spec/06-betrieb.md section 1): the admin page
 * calls each step individually, the state lives in
 * shared/update_state.json, every step is idempotent and stays far below
 * the PHP time limit (CLAUDE.md section 1 - no single request may run
 * long). After the 'switch' step the FOLLOWING requests already run on the
 * new release, so these endpoints have to exist in every version from now
 * on.
 *
 * Two steps of the final chain are missing on purpose and are added by the
 * milestone that brings what they need:
 *   - a backup before the switch (M1-4, there is no BackupService yet),
 *   - an alarm mail when a step fails (M3-1, there is no mail queue yet).
 */
final class UpdateService
{
    public const string SETTING_CHANNEL = 'update_kanal';

    /**
     * Steps in the order the admin page walks them. Kept here rather than
     * in the JavaScript so the server side owns the chain.
     */
    public const array STEPS = ['check', 'download', 'extract', 'switch', 'migrate', 'finish'];

    public function __construct(
        private readonly Paths $paths,
        private readonly string $currentVersion,
        private readonly SettingRepository $settings,
        private readonly ReleaseDownloader $downloader,
        private readonly ReleaseSwitcher $switcher,
        private readonly Migrator $migrator,
    ) {
    }

    public function channel(): string
    {
        return $this->settings->get(self::SETTING_CHANNEL, 'stable') === 'beta' ? 'beta' : 'stable';
    }

    public function setChannel(string $kanal): void
    {
        $this->settings->set(self::SETTING_CHANNEL, $kanal === 'beta' ? 'beta' : 'stable');
    }

    public function state(): ?UpdateState
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return null;
        }

        return UpdateState::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function reset(): void
    {
        $file = $this->stateFile();
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Step 1: query GitHub, compare versions, initialise the state.
     */
    public function check(): UpdateState
    {
        $release = $this->downloader->findLatestRelease($this->channel());

        if ($release === null || !version_compare($release['version'], $this->currentVersion, '>')) {
            $state = new UpdateState(
                aktuelleVersion: $this->currentVersion,
                fertig: true,
                meldungen: [$release === null
                    ? 'Kein Release gefunden.'
                    : sprintf(
                        'Version %s ist aktuell (neuestes Release: %s).',
                        $this->currentVersion,
                        $release['version'],
                    )],
            );
            $this->save($state);

            return $state;
        }

        $state = new UpdateState(
            aktuelleVersion: $this->currentVersion,
            zielVersion: $release['version'],
            zipUrl: $release['zip_url'],
            checksumsUrl: $release['checksums_url'],
            abgeschlossenerSchritt: 'check',
            meldungen: [sprintf(
                'Update auf Version %s verfügbar (Kanal %s).',
                $release['version'],
                $this->channel(),
            )],
        );
        $this->save($state);

        return $state;
    }

    /**
     * Step 2: download ZIP + checksums.txt, verify SHA-256.
     *
     * The checksums file is kept in shared/ afterwards: the code integrity
     * check of the admin area compares the installed release against it
     * (docs/spec/01-sicherheit.md section 8, milestone M13-2).
     */
    public function download(): UpdateState
    {
        return $this->step('download', function (UpdateState $state): UpdateState {
            $zipFile = $this->zipFile((string) $state->zielVersion);
            $this->downloader->downloadTo((string) $state->zipUrl, $zipFile);

            $checksums = $this->downloader->fetchText((string) $state->checksumsUrl);
            $this->downloader->verifyChecksum($zipFile, $checksums);
            file_put_contents($this->paths->releaseChecksumsFile(), $checksums, LOCK_EX);

            return $state->mit(meldung: 'Release-ZIP geladen und Prüfsumme bestätigt.');
        });
    }

    /**
     * Step 3: unpack into releases/vX.Y.Z (the running version is untouched).
     */
    public function extract(): UpdateState
    {
        return $this->step('extract', function (UpdateState $state): UpdateState {
            $version = (string) $state->zielVersion;
            $target = dirname($this->paths->releaseRoot) . '/releases/v' . $version;
            $this->downloader->extractTo($this->zipFile($version), $target);

            $versionFile = $target . '/VERSION';
            if (!is_file($versionFile) || trim((string) file_get_contents($versionFile)) !== $version) {
                throw new \RuntimeException('Entpacktes Release enthält keine passende VERSION-Datei.');
            }

            return $state->mit(meldung: 'Release entpackt nach releases/v' . $version . '.');
        });
    }

    /**
     * Step 4: atomic switch (maintenance flag around the renames).
     */
    public function switchRelease(): UpdateState
    {
        return $this->step('switch', function (UpdateState $state): UpdateState {
            $this->switcher->switchTo((string) $state->zielVersion);

            return $state->mit(meldung: 'Auf Version ' . $state->zielVersion . ' umgeschaltet.');
        });
    }

    /**
     * Step 5: apply pending migrations (already running on the new code).
     */
    public function migrate(): UpdateState
    {
        return $this->step('migrate', function (UpdateState $state): UpdateState {
            $result = $this->migrator->migrate();

            return $state->mit(meldung: $result->applied === []
                ? 'Keine Migrationen anzuwenden.'
                : sprintf(
                    '%d Migration(en) angewendet, Schema %d → %d.',
                    count($result->applied),
                    $result->fromVersion,
                    $result->toVersion,
                ));
        });
    }

    /**
     * Step 6: refresh the docroot shim, self-test, clean up, keep the last
     * two releases.
     *
     * The shim refresh happens HERE and not earlier because finish is the
     * first step running on the new release - check/download/extract and the
     * switch itself all still execute the outgoing version's code. It runs
     * BEFORE the self-test on purpose: the self-test fetches '/' through the
     * web server and therefore exercises the freshly written shim. A broken
     * shim would take the whole site down including /admin, so if the
     * self-test fails the previous shim goes back before the error is
     * reported.
     */
    public function finish(string $baseUrl): UpdateState
    {
        return $this->step('finish', function (UpdateState $state) use ($baseUrl): UpdateState {
            $vorherigerShim = null;
            $shimMeldung = null;
            try {
                $vorherigerShim = $this->switcher->refreshShim();
                if ($vorherigerShim !== null) {
                    $shimMeldung = 'Docroot-Shim aktualisiert.';
                }
            } catch (\Throwable $e) {
                // Not fatal: the old shim keeps working, it just misses the
                // newer maintenance handling. Surfaced as a message so it
                // does not stay invisible - self-healing is the only way this
                // installation ever gets the new shim.
                $shimMeldung = 'Hinweis: Docroot-Shim konnte nicht aktualisiert werden (' . $e->getMessage() . ').';
            }

            // .htaccess and .user.ini travel with the release too, and a
            // tightened CSP would otherwise never reach an existing
            // installation. Hand-edited files are kept and only reported.
            $docrootMeldungen = $this->switcher->refreshDocrootFiles();

            try {
                $this->selfTest($baseUrl);
            } catch (\Throwable $e) {
                if ($vorherigerShim !== null) {
                    $this->switcher->restoreShim($vorherigerShim);
                }
                throw $e;
            }

            $this->switcher->cleanupOldReleases();

            $zipFile = $this->zipFile((string) $state->zielVersion);
            if (is_file($zipFile)) {
                unlink($zipFile);
            }

            // One combined line on purpose: the admin page logs only the LAST
            // message of each step, so a warning in a second message would
            // never be shown.
            $teile = ['Selbsttest bestanden, alte Releases aufgeräumt.'];
            if ($shimMeldung !== null) {
                $teile[] = $shimMeldung;
            }

            return $state->mit(
                meldung: implode(' ', [...$teile, ...$docrootMeldungen]),
                fertig: true,
            );
        });
    }

    public function rollback(): UpdateState
    {
        $state = $this->state() ?? new UpdateState(aktuelleVersion: $this->currentVersion);

        try {
            $this->switcher->rollback();
            $state = $state->mit(
                meldung: 'Rollback durchgeführt – vorheriges Release ist wieder aktiv.',
                fertig: true,
            );
        } catch (\Throwable $e) {
            $state = $state->mit(fehler: $e->getMessage());
        }

        $this->save($state);

        return $state;
    }

    /**
     * Fetches the start page through the web server. That exercises the
     * shim, the release switch and the autoloader of the new version in one
     * go - the three things a release ZIP can get wrong.
     */
    private function selfTest(string $baseUrl): void
    {
        try {
            $this->downloader->fetchText(rtrim($baseUrl, '/') . '/');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Selbsttest fehlgeschlagen für /: ' . $e->getMessage());
        }
    }

    /**
     * @param \Closure(UpdateState): UpdateState $work
     */
    private function step(string $schritt, \Closure $work): UpdateState
    {
        $state = $this->state();
        if ($state === null || $state->zielVersion === null) {
            throw new \RuntimeException('Kein Update vorbereitet – zuerst den Versionscheck ausführen.');
        }

        try {
            $state = $work($state)->mit(abgeschlossenerSchritt: $schritt);
        } catch (\Throwable $e) {
            $state = $state->mit(fehler: $e->getMessage());
        }

        $this->save($state);

        return $state;
    }

    private function save(UpdateState $state): void
    {
        $file = $this->stateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $file,
            json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function stateFile(): string
    {
        return $this->paths->updateStateFile();
    }

    private function zipFile(string $version): string
    {
        return $this->paths->varDir() . '/update/vereinsbelege-v' . $version . '.zip';
    }
}
