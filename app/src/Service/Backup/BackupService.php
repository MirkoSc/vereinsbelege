<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Backup ZIPs (dump.sql + manifest.json, optionally config.php) in
 * shared/var/backups/ with rotation (docs/spec/06-betrieb.md section 2).
 * Created before every update; restore happens through the installer.
 *
 * Business data is only ever ciphertext in the dump, so the ZIP may leave
 * the server. config.php holds the server key and therefore only travels on
 * explicit request. Blobs are not part of the backup yet (M2-6).
 */
final readonly class BackupService
{
    public const int KEEP = 10;

    public function __construct(
        private \PDO $pdo,
        private string $backupDir,
        private string $configFile,
        private string $appVersion,
    ) {
    }

    /**
     * @return string the created backup filename
     */
    public function create(bool $mitConfig = false): string
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
            $zip->addFromString('manifest.json', json_encode([
                'app_version' => $this->appVersion,
                'schema_version' => $this->schemaVersion(),
                'erstellt_am' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'config_enthalten' => $configDabei,
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
