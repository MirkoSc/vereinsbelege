<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * "Backup jetzt erstellen" on /admin/backups as a step chain of short
 * requests (issue #112, CLAUDE.md section 1, docs/spec/06-betrieb.md
 * section 2): one request writes the dump, the next ones append the blob
 * files in portions, the last one writes manifest and central directory and
 * hands the finished ZIP over under a name from BackupService::reserveName().
 *
 * The result is the same backup BackupService::create() makes (dump.sql,
 * optionally config.php, blobs/, manifest.json), so the installer restores
 * both alike. Until the last step the archive is a work file
 * `erstellung_<id>.zip.part` that list() and path() never see.
 *
 * State lives in `erstellung.json` next to the backups (no session: an
 * admin may continue in another tab), guarded by an flock so two tabs cannot
 * append at once. Every step first cuts both work files back to the lengths
 * of the last committed step, so a step that died halfway is simply run
 * again.
 */
final readonly class BackupErstellung
{
    /** Blob files per step - like the restore's portions (RestoreService). */
    public const int PORTION_DATEIEN = 200;

    /** Bytes of blob files per step (at least one file). */
    public const int PORTION_BYTES = 16 * 1024 * 1024;

    /** A chain nobody has advanced for this long may be discarded by a new start. */
    public const int VERWAIST_NACH_SEKUNDEN = 3600;

    public const string PHASE_DUMP = 'dump';
    public const string PHASE_BLOBS = 'blobs';
    public const string PHASE_ABSCHLUSS = 'abschluss';
    public const string PHASE_FERTIG = 'fertig';

    private const string STATUS = 'erstellung.json';
    private const string SPERRE = 'erstellung.lock';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private BackupService $backups,
        private \PDO $pdo,
        private string $backupDir,
        private string $configFile,
        private ?\Closure $clock = null,
    ) {
    }

    /**
     * The current or last chain, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function stand(): ?array
    {
        $pfad = $this->backupDir . '/' . self::STATUS;
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);

        return is_array($daten) && isset($daten['id'], $daten['phase']) ? $daten : null;
    }

    /**
     * Whether a chain is running and was advanced recently.
     */
    public function laeuft(): bool
    {
        $stand = $this->stand();

        return $stand !== null
            && $stand['phase'] !== self::PHASE_FERTIG
            && $this->jetzt()->getTimestamp() - (int) $stand['aktualisiert'] < self::VERWAIST_NACH_SEKUNDEN;
    }

    /**
     * Starts a new chain; nothing is written to the archive yet.
     *
     * @return array<string, mixed>
     *
     * @throws BackupLaeuftBereits when another chain is still running
     */
    public function starten(bool $mitConfig): array
    {
        return $this->gesperrt(function () use ($mitConfig): array {
            if ($this->laeuft()) {
                throw new BackupLaeuftBereits('Es wird bereits ein Backup erstellt.');
            }
            $this->arbeitsdateienLoeschen();

            $jetzt = $this->jetzt();
            $stand = [
                'id' => bin2hex(random_bytes(8)),
                'phase' => self::PHASE_DUMP,
                'mit_config' => $mitConfig,
                'config_enthalten' => false,
                'gestartet' => $jetzt->format('Y-m-d H:i:s'),
                'aktualisiert' => $jetzt->getTimestamp(),
                'offset' => 0,
                'zentral' => 0,
                'eintraege' => 0,
                'cursor' => '',
                'blob_dateien' => 0,
                'blob_bytes' => 0,
                'blob_gesamt' => 0,
                'name' => null,
            ];
            $this->speichern($stand);

            return $stand;
        });
    }

    /**
     * Runs the next step of the running chain.
     *
     * @return array<string, mixed> the state after the step; `phase` is
     *         PHASE_FERTIG and `name` set once the backup exists
     */
    public function schritt(): array
    {
        return $this->gesperrt(function (): array {
            $stand = $this->stand();
            if ($stand === null) {
                throw new \RuntimeException('Es läuft keine Backup-Erstellung.');
            }
            if ($stand['phase'] === self::PHASE_FERTIG) {
                return $stand;
            }

            $archivPfad = $this->arbeitsdatei($stand, 'zip.part');
            $zentralPfad = $this->arbeitsdatei($stand, 'zentral.part');
            if ($stand['phase'] !== self::PHASE_DUMP && (!is_file($archivPfad) || !is_file($zentralPfad))) {
                throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
            }

            $archiv = $this->oeffnen($archivPfad, (int) $stand['offset']);
            $zentral = $this->oeffnen($zentralPfad, (int) $stand['zentral']);
            try {
                $zip = new ZipAnhang($archiv, $zentral, new \DateTimeImmutable((string) $stand['gestartet']));
                $stand = match ($stand['phase']) {
                    self::PHASE_DUMP => $this->dump($stand, $zip),
                    self::PHASE_BLOBS => $this->blobs($stand, $zip),
                    self::PHASE_ABSCHLUSS => $this->abschluss($stand, $zip),
                    default => throw new \RuntimeException('Unbekannter Schritt der Backup-Erstellung.'),
                };
                $stand['offset'] = (int) ftell($archiv);
                $stand['zentral'] = (int) ftell($zentral);
            } finally {
                fclose($archiv);
                fclose($zentral);
            }

            if ($stand['phase'] === self::PHASE_FERTIG) {
                $stand = $this->uebergeben($stand, $archivPfad, $zentralPfad);
            }
            $stand['aktualisiert'] = $this->jetzt()->getTimestamp();
            $this->speichern($stand);

            return $stand;
        });
    }

    /**
     * Discards the running chain and its work files.
     */
    public function abbrechen(): void
    {
        $this->gesperrt(function (): null {
            $this->arbeitsdateienLoeschen();
            $status = $this->backupDir . '/' . self::STATUS;
            if (is_file($status)) {
                unlink($status);
            }

            return null;
        });
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function dump(array $stand, ZipAnhang $zip): array
    {
        $dumpPfad = $this->arbeitsdatei($stand, 'dump.part');
        try {
            $strom = fopen($dumpPfad, 'w+b');
            if ($strom === false) {
                throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
            }
            try {
                new MysqlDumper($this->pdo)->dump($strom);
                rewind($strom);
                // Level 1: the dump is mostly hex literals of ciphertext;
                // a higher level costs CPU time for little gain.
                $zip->eintrag('dump.sql', $strom, ZipAnhang::DEFLATE, 1);
            } finally {
                fclose($strom);
            }
        } finally {
            if (is_file($dumpPfad)) {
                unlink($dumpPfad);
            }
        }
        $eintraege = 1;

        $configDabei = $stand['mit_config'] === true && is_file($this->configFile);
        if ($configDabei) {
            $config = fopen($this->configFile, 'rb');
            if ($config === false) {
                throw new \RuntimeException('config.php kann nicht gelesen werden.');
            }
            try {
                $zip->eintrag('config.php', $config);
            } finally {
                fclose($config);
            }
            $eintraege++;
        }

        return [
            ...$stand,
            'phase' => self::PHASE_BLOBS,
            'config_enthalten' => $configDabei,
            'eintraege' => $eintraege,
            'blob_gesamt' => count($this->backups->blobFiles()),
        ];
    }

    /**
     * Appends the next portion of blob files, in the sorted order of their
     * entry names after `cursor`. A file deleted meanwhile is skipped; one
     * added meanwhile is taken if it sorts behind the cursor - either way
     * it has no row in the dump taken at the start.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function blobs(array $stand, ZipAnhang $zip): array
    {
        $cursor = (string) $stand['cursor'];
        $dateien = 0;
        $bytes = 0;
        $fertig = true;
        foreach ($this->backups->blobFiles() as $eintrag => $pfad) {
            $eintrag = (string) $eintrag;
            if (strcmp($eintrag, $cursor) <= 0) {
                continue;
            }
            if ($dateien >= self::PORTION_DATEIEN || ($dateien > 0 && $bytes >= self::PORTION_BYTES)) {
                $fertig = false;
                break;
            }
            $quelle = @fopen($pfad, 'rb');
            if ($quelle === false) {
                continue;
            }
            try {
                $bytes += $zip->eintrag($eintrag, $quelle);
            } finally {
                fclose($quelle);
            }
            $dateien++;
            $cursor = $eintrag;
        }

        return [
            ...$stand,
            'phase' => $fertig ? self::PHASE_ABSCHLUSS : self::PHASE_BLOBS,
            'cursor' => $cursor,
            'eintraege' => (int) $stand['eintraege'] + $dateien,
            'blob_dateien' => (int) $stand['blob_dateien'] + $dateien,
            'blob_bytes' => (int) $stand['blob_bytes'] + $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function abschluss(array $stand, ZipAnhang $zip): array
    {
        $zip->eintragAusText('manifest.json', $this->backups->manifest(
            new \DateTimeImmutable((string) $stand['gestartet']),
            (bool) $stand['config_enthalten'],
            (int) $stand['blob_dateien'],
            (int) $stand['blob_bytes'],
        ));
        $zip->abschliessen((int) $stand['eintraege'] + 1);

        return [...$stand, 'phase' => self::PHASE_FERTIG, 'eintraege' => (int) $stand['eintraege'] + 1];
    }

    /**
     * Moves the finished work file onto a freshly reserved backup name.
     * rename() replaces the empty placeholder reserveName() created, so
     * list() never sees a half-written archive.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function uebergeben(array $stand, string $archivPfad, string $zentralPfad): array
    {
        [$name, $zielPfad] = $this->backups->reserveName(new \DateTimeImmutable((string) $stand['gestartet']));
        if (!@rename($archivPfad, $zielPfad)) {
            @unlink($zielPfad);
            throw new \RuntimeException('Das fertige Backup konnte nicht abgelegt werden.');
        }
        @chmod($zielPfad, 0600);
        if (is_file($zentralPfad)) {
            unlink($zentralPfad);
        }
        $this->backups->rotate();

        return [...$stand, 'name' => $name];
    }

    /**
     * @return resource opened for reading and writing, cut back to $laenge
     *         and positioned at its end
     */
    private function oeffnen(string $pfad, int $laenge)
    {
        $datei = fopen($pfad, 'c+b');
        if ($datei === false) {
            throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
        }
        if (!ftruncate($datei, $laenge) || fseek($datei, $laenge) !== 0) {
            fclose($datei);
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
        }

        return $datei;
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function arbeitsdatei(array $stand, string $endung): string
    {
        return sprintf('%s/erstellung_%s.%s', $this->backupDir, (string) $stand['id'], $endung);
    }

    private function arbeitsdateienLoeschen(): void
    {
        foreach (glob($this->backupDir . '/erstellung_*.part') ?: [] as $datei) {
            @unlink($datei);
        }
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function speichern(array $stand): void
    {
        $pfad = $this->backupDir . '/' . self::STATUS;
        $tmp = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($stand, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new \RuntimeException('Backup-Status kann nicht gespeichert werden.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function gesperrt(\Closure $arbeit): mixed
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException('Backup-Verzeichnis kann nicht angelegt werden: ' . $this->backupDir);
        }
        $sperre = fopen($this->backupDir . '/' . self::SPERRE, 'c');
        if ($sperre === false) {
            throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                throw new BackupLaeuftBereits('Ein Schritt der Backup-Erstellung läuft gerade.');
            }

            return $arbeit();
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private function jetzt(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
    }
}
