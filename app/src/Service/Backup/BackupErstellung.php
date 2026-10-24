<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * "Backup jetzt erstellen" on /admin/backups and the update's backup step
 * as a step chain of short requests (issue #112, CLAUDE.md section 1,
 * docs/spec/06-betrieb.md section 2): the first requests write the database
 * dump in parts (issue #208, MysqlDumper::schritt()) straight into the open
 * ZIP entry dump.sql, the next ones append the blob files in portions, the
 * last one writes manifest and central directory and hands the finished ZIP
 * over under a name from BackupService::reserveName().
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

    /**
     * Dump text per step: the step writes it to a work file and then packs
     * it into the archive, both within the request.
     */
    public const int DUMP_BYTES = 32 * 1024 * 1024;

    /** Seconds of dumping per step - well inside max_execution_time = 30. */
    public const float DUMP_SEKUNDEN = 8.0;

    /** Marks a chain the update started (UpdateService::backup()). */
    public const string ZWECK_UPDATE = 'update';

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
        private int $dumpBytes = self::DUMP_BYTES,
        private float $dumpSekunden = self::DUMP_SEKUNDEN,
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
     * @param bool $mitBlobs false leaves the files of backend `fs` out (the
     *        update's backup: an update never touches them)
     * @param string|null $zweck who started it, so that caller can tell its
     *        own chain from one an admin started (ZWECK_UPDATE)
     *
     * @return array<string, mixed>
     *
     * @throws BackupLaeuftBereits when another chain is still running
     */
    public function starten(bool $mitConfig, bool $mitBlobs = true, ?string $zweck = null): array
    {
        return $this->gesperrt(function () use ($mitConfig, $mitBlobs, $zweck): array {
            if ($this->laeuft()) {
                throw new BackupLaeuftBereits('Es wird bereits ein Backup erstellt.');
            }
            $this->arbeitsdateienLoeschen();

            $jetzt = $this->jetzt();
            $stand = [
                'id' => bin2hex(random_bytes(8)),
                'phase' => self::PHASE_DUMP,
                'mit_config' => $mitConfig,
                'mit_blobs' => $mitBlobs,
                'zweck' => $zweck,
                'dump' => null,
                'dump_eintrag' => null,
                'dump_zeilen' => 0,
                'config_enthalten' => false,
                'gestartet' => $jetzt->format('Y-m-d H:i:s'),
                'aktualisiert' => $jetzt->getTimestamp(),
                'offset' => 0,
                'zentral' => 0,
                'eintraege' => 0,
                'liste_offset' => 0,
                'blob_dateien' => 0,
                'blob_bytes' => 0,
                'blob_gesamt' => 0,
                'name' => null,
            ];
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => false];
        });
    }

    /**
     * Runs the next step of the running chain.
     *
     * @return array<string, mixed> the state after the step; `phase` is
     *         PHASE_FERTIG and `name` set once the backup exists.
     *         `gerade_fertig` is true only in the answer of the one step
     *         that finished it - decided under the lock, so two tabs cannot
     *         both report (and audit) the same backup.
     */
    public function schritt(): array
    {
        return $this->gesperrt(function (): array {
            $stand = $this->stand();
            if ($stand === null) {
                throw new \RuntimeException('Es läuft keine Backup-Erstellung.');
            }
            if ($stand['phase'] === self::PHASE_FERTIG) {
                return [...$stand, 'gerade_fertig' => false];
            }

            $archivPfad = $this->arbeitsdatei($stand, 'zip.part');
            $zentralPfad = $this->arbeitsdatei($stand, 'zentral.part');
            // Once dump.sql is open (or the dump is through) the archive
            // has committed bytes: a missing work file must not be recreated
            // empty and padded to the old length.
            $begonnen = $stand['phase'] !== self::PHASE_DUMP || is_array($stand['dump_eintrag'] ?? null);
            if ($begonnen && (!is_file($archivPfad) || !is_file($zentralPfad))) {
                $gerettet = $this->nachAbbruchDerUebergabe($stand);
                if ($gerettet !== null) {
                    return $gerettet;
                }
                throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
            }

            $vorher = $stand;
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
                return $this->uebergeben($vorher, $stand, $archivPfad);
            }
            $stand['aktualisiert'] = $this->jetzt()->getTimestamp();
            $this->speichern($stand);

            return [...$stand, 'gerade_fertig' => false];
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
        // The open entry dump.sql and the dumper's position travel with the
        // committed lengths of the work files: a step that died is run again
        // from exactly here, its bytes in the archive cut off by oeffnen().
        $eintrag = is_array($stand['dump_eintrag'] ?? null)
            ? $stand['dump_eintrag']
            : $zip->eintragBeginnen('dump.sql', ZipAnhang::DEFLATE);
        $dumpStand = is_array($stand['dump'] ?? null) ? $stand['dump'] : null;

        $dumpPfad = $this->arbeitsdatei($stand, 'dump.part');
        try {
            $strom = fopen($dumpPfad, 'w+b');
            if ($strom === false) {
                throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
            }
            try {
                $dumpStand = new MysqlDumper($this->pdo)->schritt($strom, $dumpStand, $this->dumpBytes, $this->dumpSekunden);
                rewind($strom);
                // Level 1: the dump is mostly hex literals of ciphertext;
                // a higher level costs CPU time for little gain.
                $eintrag = $zip->eintragFortsetzen($eintrag, $strom, 1);
            } finally {
                fclose($strom);
            }
        } finally {
            if (is_file($dumpPfad)) {
                unlink($dumpPfad);
            }
        }

        if ($dumpStand['fertig'] !== true) {
            return [
                ...$stand,
                'dump' => $dumpStand,
                'dump_eintrag' => $eintrag,
                'dump_zeilen' => (int) $dumpStand['zeilen'],
            ];
        }
        $zip->eintragBeenden($eintrag);
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

        // The blob files to pack, fixed right after the dump: the rows in
        // the dump and this list belong together. Later steps read it from
        // where the last one stopped instead of listing the tree again.
        $liste = '';
        $anzahl = 0;
        $dateien = ($stand['mit_blobs'] ?? true) === false ? [] : $this->backups->blobFiles();
        foreach ($dateien as $zipName => $pfad) {
            $liste .= $zipName . "\t" . $pfad . "\n";
            $anzahl++;
        }
        if (file_put_contents($this->arbeitsdatei($stand, 'liste.part'), $liste) === false) {
            throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
        }

        return [
            ...$stand,
            'phase' => self::PHASE_BLOBS,
            'dump' => null,
            'dump_eintrag' => null,
            'dump_zeilen' => (int) $dumpStand['zeilen'],
            'config_enthalten' => $configDabei,
            'eintraege' => $eintraege,
            'liste_offset' => 0,
            'blob_gesamt' => $anzahl,
        ];
    }

    /**
     * Appends the next portion of the blob files listed after the dump.
     *
     * A listed file that is gone or unreadable stops the chain with an
     * error rather than being left out: its row is in the dump, and a
     * backup that silently lacks a receipt file must never be reported as
     * made. (A storage switch or a deletion while the chain runs causes
     * this - starting the backup again fixes it.)
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function blobs(array $stand, ZipAnhang $zip): array
    {
        $liste = @fopen($this->arbeitsdatei($stand, 'liste.part'), 'rb');
        if ($liste === false) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups fehlt – bitte abbrechen und neu starten.');
        }
        try {
            if (fseek($liste, (int) $stand['liste_offset']) !== 0) {
                throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
            }
            $offset = (int) $stand['liste_offset'];
            $dateien = 0;
            $bytes = 0;
            $fertig = false;
            while (true) {
                $zeile = fgets($liste);
                if ($zeile === false) {
                    $fertig = true;
                    break;
                }
                if ($dateien >= self::PORTION_DATEIEN || ($dateien > 0 && $bytes >= self::PORTION_BYTES)) {
                    // Portion full; this line is the next step's first.
                    break;
                }
                [$eintrag, $pfad] = explode("\t", rtrim($zeile, "\n"), 2) + [1 => ''];
                $quelle = is_file($pfad) ? @fopen($pfad, 'rb') : false;
                if ($quelle === false) {
                    throw new \RuntimeException(
                        'Eine Belegdatei ist seit Beginn des Backups verschwunden oder nicht lesbar '
                        . '(z. B. durch einen Speicher-Umzug) – bitte das Backup abbrechen und neu starten.',
                    );
                }
                try {
                    $bytes += $zip->eintrag($eintrag, $quelle);
                } finally {
                    fclose($quelle);
                }
                $dateien++;
                $offset += strlen($zeile);
            }
        } finally {
            fclose($liste);
        }

        return [
            ...$stand,
            'phase' => $fertig ? self::PHASE_ABSCHLUSS : self::PHASE_BLOBS,
            'liste_offset' => $offset,
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
     * The reserved name is saved BEFORE the rename (with the state of
     * before the last step): if the request dies right after the rename,
     * the next step finds the backup under that name instead of reporting
     * a missing work file (nachAbbruchDerUebergabe()).
     *
     * @param array<string, mixed> $vorher state before the last step
     * @param array<string, mixed> $stand state after it
     * @return array<string, mixed>
     */
    private function uebergeben(array $vorher, array $stand, string $archivPfad): array
    {
        $this->platzhalterLoeschen($vorher);
        [$name, $zielPfad] = $this->backups->reserveName(new \DateTimeImmutable((string) $stand['gestartet']));
        $this->speichern([...$vorher, 'ziel' => $name]);
        if (!@rename($archivPfad, $zielPfad)) {
            @unlink($zielPfad);
            throw new \RuntimeException('Das fertige Backup konnte nicht abgelegt werden.');
        }

        return $this->abschliessen([...$stand, 'ziel' => $name], $name);
    }

    /**
     * The work file is gone in a later phase: if the last step died after
     * moving the finished archive onto its name, finish the chain now.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>|null
     */
    private function nachAbbruchDerUebergabe(array $stand): ?array
    {
        $ziel = $stand['ziel'] ?? null;
        if ($stand['phase'] !== self::PHASE_ABSCHLUSS || !is_string($ziel)) {
            return null;
        }
        $pfad = $this->backups->path($ziel);
        if ($pfad === null || filesize($pfad) === 0) {
            return null;
        }

        return $this->abschliessen([...$stand, 'phase' => self::PHASE_FERTIG], $ziel);
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function abschliessen(array $stand, string $name): array
    {
        $fertig = [...$stand, 'phase' => self::PHASE_FERTIG, 'name' => $name, 'aktualisiert' => $this->jetzt()->getTimestamp()];
        $this->speichern($fertig);
        $this->arbeitsdateienLoeschen();
        try {
            $this->backups->rotate();
        } catch (\Throwable) {
            // The backup exists and is recorded; the next backup rotates
            // again. Failing here would only hide that it was made.
        }

        return [...$fertig, 'gerade_fertig' => true];
    }

    /**
     * An earlier attempt of the hand-over may have left its reserved,
     * still empty placeholder behind.
     *
     * @param array<string, mixed> $stand
     */
    private function platzhalterLoeschen(array $stand): void
    {
        $ziel = $stand['ziel'] ?? null;
        if (!is_string($ziel)) {
            return;
        }
        $pfad = $this->backups->path($ziel);
        if ($pfad !== null && filesize($pfad) === 0) {
            @unlink($pfad);
        }
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
