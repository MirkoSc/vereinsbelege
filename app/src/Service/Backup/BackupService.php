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

    /** Prefix of the blob files inside the ZIP. */
    public const string BLOB_PREFIX = 'blobs/';

    public function __construct(
        private \PDO $pdo,
        private string $backupDir,
        private string $configFile,
        private string $appVersion,
        private string $blobDir,
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

        try {
            $stream = fopen($dumpFile, 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
            }
            try {
                new MysqlDumper($this->pdo)->dump($stream);
            } finally {
                fclose($stream);
            }

            $configDabei = $mitConfig && is_file($this->configFile);
            $name = 'backup_' . new \DateTimeImmutable()->format('Ymd_His') . '.zip';
            $zipPath = $this->backupDir . '/' . $name;

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

            $zip->addFromString('manifest.json', json_encode([
                'app_version' => $this->appVersion,
                'schema_version' => $this->schemaVersion(),
                'erstellt_am' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'config_enthalten' => $configDabei,
                'blob_dateien' => $blobDateien,
                'blob_bytes' => $blobBytes,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            // ZipArchive reads the added files on close(), so the dump must
            // still exist until here.
            if (!$zip->close()) {
                throw new \RuntimeException('Backup-ZIP konnte nicht geschrieben werden: ' . $zipPath);
            }
        } finally {
            if (is_file($dumpFile)) {
                unlink($dumpFile);
            }
        }

        $this->rotate();

        return $name;
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
        $dateien = glob($this->blobDir . '/*/*') ?: [];
        sort($dateien);

        $anzahl = 0;
        foreach ($dateien as $pfad) {
            $name = basename($pfad);
            $unterordner = basename(dirname($pfad));
            if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1 || $unterordner !== substr($name, 0, 2)) {
                continue;
            }
            if (!is_file($pfad)) {
                continue;
            }

            $eintrag = self::BLOB_PREFIX . $unterordner . '/' . $name;
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
        usort($backups, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Validated path; refuses anything but a plain backup filename (no
     * traversal).
     */
    public function path(string $name): ?string
    {
        if (preg_match('/^backup_\d{8}_\d{6}\.zip$/', $name) !== 1) {
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

    private function rotate(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            unlink($this->backupDir . '/' . $old['name']);
        }
    }
}
