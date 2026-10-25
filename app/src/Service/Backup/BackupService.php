<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Backup ZIPs (dump.sql + manifest.json + blobs/, optionally config.php) in
 * shared/var/backups/ with rotation (docs/spec/06-betrieb.md section 2).
 * Created before every update; restore happens through the installer.
 *
 * Business data is only ever ciphertext - in the dump as well as in the blob
 * files - so the ZIP may leave the server. config.php holds the server key
 * and therefore only travels on explicit request.
 *
 * Both storage backends end up in the ZIP (M2-6): the chunks of backend `db`
 * ride along in the dump, the files of backend `fs` are added from
 * shared/var/blobs/. What is packed is what lies in that directory, not what
 * the `speicher_backend` setting currently says - right after a backend
 * switch (M2-5) the mixed state is the normal one.
 */
final readonly class BackupService
{
    public const int KEEP = 10;

    /**
     * How many backups may share one second before create() gives up - a
     * second one gets `_2`, a third `_3` (issue #155: two runs in the same
     * second used to overwrite each other silently).
     */
    public const int MAX_PER_SECOND = 100;

    /**
     * Printed by bin/backup.php for every backup made without config.php
     * (issue #154): the default backup restores into an installation nobody
     * can log in to until an admin re-links their account - said loudly at
     * the moment the backup is made, not only at the restore.
     */
    public const string WARNUNG_OHNE_CONFIG = 'WARNING: this backup does NOT contain shared/config.php (server key). '
        . 'Restored on another server without that key, nobody can log in until an admin re-links '
        . 'their account in the installer with the recovery key and their password; mail addresses '
        . 'of the other users, authenticator secrets, AI API keys and the SMTP password are lost '
        . '(docs/spec/06-betrieb.md section 2). Keep a copy of shared/config.php next to the recovery key.';

    /** Prefix of the blob files inside the ZIP. */
    public const string BLOB_PREFIX = 'blobs/';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock the time the
     *        filename and the manifest carry; tests pin it to force two
     *        backups into the same second
     */
    public function __construct(
        private \PDO $pdo,
        private string $backupDir,
        private string $configFile,
        private string $appVersion,
        private string $blobDir,
        private ?\Closure $clock = null,
    ) {
    }

    /**
     * @return string the created backup filename
     */
    public function create(bool $mitConfig = false, bool $mitBlobs = true): string
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException('Backup-Verzeichnis kann nicht angelegt werden: ' . $this->backupDir);
        }

        // Random name: two requests creating a backup at once must not
        // delete each other's temporary dump.
        $dumpFile = $this->backupDir . '/dump_' . bin2hex(random_bytes(8)) . '.tmp';
        $zipPath = null;

        try {
            $stream = fopen($dumpFile, 'wb');
            self::nurFuerBesitzer($dumpFile);
            if ($stream === false) {
                throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
            }
            try {
                new MysqlDumper($this->pdo)->dump($stream);
            } finally {
                fclose($stream);
            }

            $configDabei = $mitConfig && is_file($this->configFile);
            $jetzt = $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
            [$name, $zipPath] = $this->reserveName($jetzt);

            // OVERWRITE only truncates the empty file reserveName() just
            // created - nothing else is ever overwritten.
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Backup-ZIP kann nicht erstellt werden: ' . $zipPath);
            }
            $zip->addFile($dumpFile, 'dump.sql');
            if ($configDabei) {
                $zip->addFile($this->configFile, 'config.php');
            }

            $blobBytes = 0;
            $blobDateien = $mitBlobs ? $this->addBlobs($zip, $blobBytes) : 0;

            $zip->addFromString('manifest.json', $this->manifest($jetzt, $configDabei, $blobDateien, $blobBytes));

            // ZipArchive reads the added files on close(), so the dump must
            // still exist until here.
            if (!$zip->close()) {
                throw new \RuntimeException('Backup-ZIP konnte nicht geschrieben werden: ' . $zipPath);
            }
            self::nurFuerBesitzer($zipPath);
        } catch (\Throwable $e) {
            // The reserved name must not survive as an empty or half-written
            // ZIP that list() would then offer as a backup.
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            if (is_file($dumpFile)) {
                unlink($dumpFile);
            }
        }

        $this->rotate();

        return $name;
    }

    /**
     * 0600 like shared/config.php itself (Installer\ConfigWriter): a backup
     * may carry config.php and with it the server key, and even without it
     * there is no reason for anyone but the owner to read one (security
     * review, issue #78). Best effort, as with config.php - a host that does
     * not allow chmod keeps its default and loses nothing else.
     */
    public static function nurFuerBesitzer(string $pfad): void
    {
        @chmod($pfad, 0600);
    }

    /**
     * The next free filename for this second, taken atomically: fopen() in
     * mode `x` fails when the file exists, so two backups started at once
     * cannot both claim the same name. The first gets
     * `backup_YYYYMMDD_HHMMSS.zip`, the next ones `_2`, `_3`, ... - list()
     * sorts naturally, so the later one still comes first.
     *
     * @return array{string, string} name and full path of the reserved file
     */
    public function reserveName(\DateTimeImmutable $jetzt): array
    {
        $stempel = $jetzt->format('Ymd_His');
        for ($n = 1; $n <= self::MAX_PER_SECOND; $n++) {
            $name = $n === 1 ? sprintf('backup_%s.zip', $stempel) : sprintf('backup_%s_%d.zip', $stempel, $n);
            $zipPath = $this->backupDir . '/' . $name;
            $handle = @fopen($zipPath, 'x');
            if ($handle !== false) {
                fclose($handle);
                self::nurFuerBesitzer($zipPath);

                return [$name, $zipPath];
            }
            if (!is_file($zipPath)) {
                throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
            }
        }

        throw new \RuntimeException('Zu viele Backups in derselben Sekunde: ' . $stempel);
    }

    /**
     * Adds the ciphertext files of storage backend `fs` as
     * blobs/<2 chars>/<random id>, exactly the layout of
     * Service\Storage\FsBlobBackend.
     *
     * Only finished blobs travel: a `.part` file is a write that broke off
     * (FsBlobSink) and has no row that could ever read it. Ciphertext does
     * not compress, so the entries are stored rather than deflated - that is
     * pure runtime on a shared host.
     *
     * @param int $bytes receives the ciphertext size of everything added
     * @return int number of files added
     */
    private function addBlobs(\ZipArchive $zip, int &$bytes): int
    {
        $anzahl = 0;
        foreach ($this->blobFiles() as $eintrag => $pfad) {
            if (!$zip->addFile($pfad, $eintrag)) {
                throw new \RuntimeException('Blob konnte dem Backup nicht hinzugefügt werden.');
            }
            $zip->setCompressionName($eintrag, \ZipArchive::CM_STORE);
            $bytes += (int) filesize($pfad);
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * The finished blob files of backend `fs`, sorted by entry name - the
     * same selection for create() and the admin page's step chain
     * (BackupErstellung).
     *
     * @return array<string, string> ZIP entry name => path on disk
     */
    public function blobFiles(): array
    {
        $dateien = glob($this->blobDir . '/*/*') ?: [];
        sort($dateien);

        $ergebnis = [];
        foreach ($dateien as $pfad) {
            $name = basename($pfad);
            $unterordner = basename(dirname($pfad));
            if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1 || $unterordner !== substr($name, 0, 2)) {
                continue;
            }
            if (!is_file($pfad)) {
                continue;
            }
            $ergebnis[self::BLOB_PREFIX . $unterordner . '/' . $name] = $pfad;
        }

        return $ergebnis;
    }

    /**
     * manifest.json - only numbers and versions, nothing about the club's
     * business (docs/spec/06-betrieb.md section 2).
     */
    public function manifest(\DateTimeImmutable $jetzt, bool $configDabei, int $blobDateien, int $blobBytes): string
    {
        return json_encode([
            'app_version' => $this->appVersion,
            'schema_version' => $this->schemaVersion(),
            'erstellt_am' => $jetzt->format('Y-m-d H:i:s'),
            'config_enthalten' => $configDabei,
            'blob_dateien' => $blobDateien,
            'blob_bytes' => $blobBytes,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Whether the backup carries shared/config.php (the server key) - read
     * from the archive itself, not from manifest.json, so a renamed or
     * hand-made ZIP cannot hide it.
     */
    public function containsConfig(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            // Unreadable: treat it as the sensitive kind, never the other way round.
            return true;
        }
        try {
            return $zip->locateName('config.php') !== false;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<array{name: string, groesse: int, geaendert_am: string}> newest first
     */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->backupDir . '/backup_*.zip') ?: [] as $path) {
            $backups[] = [
                'name' => basename($path),
                'groesse' => (int) filesize($path),
                'geaendert_am' => date('Y-m-d H:i:s', (int) filemtime($path)),
            ];
        }
        // Natural order, so that the same-second suffix `_10` sorts after
        // `_9` and `backup_X.zip` before `backup_X_2.zip` (reserveName()).
        usort($backups, static fn(array $a, array $b): int => strnatcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Validated path; refuses anything but a plain backup filename (no
     * traversal). The optional `_N` is the same-second suffix of
     * reserveName().
     */
    public function path(string $name): ?string
    {
        if (preg_match('/^backup_\d{8}_\d{6}(_\d{1,3})?\.zip$/', $name) !== 1) {
            return null;
        }
        $path = $this->backupDir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    private function schemaVersion(): int
    {
        try {
            return (int) $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        } catch (\PDOException) {
            // table may not exist on a broken instance; still back up
            return 0;
        }
    }

    public function rotate(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            unlink($this->backupDir . '/' . $old['name']);
        }
    }
}
