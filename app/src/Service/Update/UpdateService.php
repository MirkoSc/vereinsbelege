<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Backup\BackupErstellung;
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
 * One step of the final chain is missing on purpose and is added by the
 * milestone that brings what it needs: an alarm mail when a step fails
 * (M3-1, there is no mail queue yet).
 */
final class UpdateService
{
    public const string SETTING_CHANNEL = 'update_kanal';

    /**
     * Steps in the order the admin page walks them. Kept here rather than
     * in the JavaScript so the server side owns the chain.
     */
    public const array STEPS = ['check', 'download', 'extract', 'backup', 'switch', 'migrate', 'finish'];

    /**
     * A call of the backup step starts no further chain step after this
     * many seconds. The step already running finishes on its own budget
     * (BackupErstellung::DUMP_SEKUNDEN plus one portion), so a call lasts
     * at most this plus one step - inside max_execution_time = 30.
     */
    public const float BACKUP_SEKUNDEN = 5.0;

    public function __construct(
        private readonly Paths $paths,
        private readonly string $currentVersion,
        private readonly SettingRepository $settings,
        private readonly ReleaseDownloader $downloader,
        private readonly ReleaseSwitcher $switcher,
        private readonly Migrator $migrator,
        private readonly BackupErstellung $backups,
        private readonly float $backupSekunden = self::BACKUP_SEKUNDEN,
        /**
         * Why a rollback must not happen right now, or null (issue #90):
         * a release before migration 039 reads `vault` as "newest row is
         * the key" and would seal to a vault key rotation's pending key.
         * Asked lazily - the update pages must work without a database.
         *
         * @var (\Closure(): ?string)|null
         */
        private readonly ?\Closure $rollbackHindernis = null,
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
     * Step 4: database backup right before the only step that changes the
     * installation. Without config.php: the server key stays out of files
     * that may be copied around (docs/spec/06-betrieb.md section 2).
     *
     * Without blob files either: an update never touches shared/var/blobs/
     * - what it can break is the schema, and that is what the dump brings
     * back.
     *
     * The same step chain as "Backup jetzt erstellen" (BackupErstellung):
     * with storage backend `db` the receipts are rows of the dump, which
     * then takes many requests (issue #208). One call runs steps for up to
     * BACKUP_SEKUNDEN and answers `weiter` while the chain is not through;
     * the page calls the step again. A small database is done in one call.
     */
    public function backup(): UpdateState
    {
        return $this->step('backup', function (UpdateState $state): UpdateState {
            $stand = $this->backups->stand();
            $eigene = $stand !== null
                && ($stand['zweck'] ?? null) === BackupErstellung::ZWECK_UPDATE
                && $this->backups->laeuft();
            if (!$eigene) {
                $this->backups->starten(mitConfig: false, mitBlobs: false, zweck: BackupErstellung::ZWECK_UPDATE);
            }

            $beginn = hrtime(true);
            do {
                $stand = $this->backups->schritt();
            } while ($stand['phase'] !== BackupErstellung::PHASE_FERTIG
                && (hrtime(true) - $beginn) / 1e9 < $this->backupSekunden);

            if ($stand['phase'] !== BackupErstellung::PHASE_FERTIG) {
                return $state->mit(weiter: true);
            }

            return $state->mit(meldung: 'Backup erstellt: ' . (string) $stand['name']);
        });
    }

    /**
     * Step 5: atomic switch (maintenance flag around the renames).
     */
    public function switchRelease(): UpdateState
    {
        return $this->step('switch', function (UpdateState $state): UpdateState {
            $this->switcher->switchTo((string) $state->zielVersion);

            return $state->mit(meldung: 'Auf Version ' . $state->zielVersion . ' umgeschaltet.');
        });
    }

    /**
     * Step 6: apply pending migrations (already running on the new code).
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
     * Step 7: refresh the docroot shim, self-test, clean up, keep the last
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
            // shared/, releases/ and current/ get their deny-all .htaccess
            // here when setup.php was older than issue #101.
            $docrootMeldungen = [
                ...$this->switcher->refreshDocrootFiles(),
                ...$this->switcher->lockPrivateDirectories(),
            ];

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

        $hindernis = $this->rollbackHindernis === null ? null : ($this->rollbackHindernis)();
        if ($hindernis !== null) {
            $state = $state->mit(fehler: $hindernis);
            $this->save($state);

            return $state;
        }

        try {
            $this->switcher->rollback();
            // The previous release may predate issue #101 and come back as
            // current/ without its deny-all .htaccess.
            $state = $state->mit(
                meldung: implode(' ', [
                    'Rollback durchgeführt – vorheriges Release ist wieder aktiv.',
                    ...$this->switcher->lockPrivateDirectories(),
                ]),
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
            $neu = $work($state);
            // A step that answers `weiter` is not through: it stays the
            // open step, so a reload resumes it instead of skipping it.
            $state = $neu->weiter ? $neu : $neu->mit(abgeschlossenerSchritt: $schritt);
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
