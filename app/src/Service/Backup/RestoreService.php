<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Installer\ConfigWriter;
use App\Service\Migration\SqlSplitter;

/**
 * The domain side of restoring a backup ZIP in the installer: no Http, no
 * session, so it runs the same in a test as in the installer.
 *
 * The ZIP is an upload and therefore untrusted. Nothing in it is ever
 * executed: config.php is only searched for the server key with a regular
 * expression, never included.
 */
final readonly class RestoreService
{
    /** Statements per request - no single request may run long (CLAUDE.md section 1). */
    public const int STATEMENTS_PER_STEP = 200;

    /**
     * Copies dump.sql out of the ZIP.
     *
     * @throws \RuntimeException when the file is not a usable backup
     */
    public function dumpAusZip(string $zipPfad, string $zielDatei): void
    {
        $zip = $this->open($zipPfad);
        try {
            $stream = $zip->getStream('dump.sql');
            if ($stream === false) {
                throw new \RuntimeException('Das ZIP ist kein gültiges Backup (dump.sql fehlt).');
            }
            $ziel = fopen($zielDatei, 'wb');
            if ($ziel === false) {
                fclose($stream);
                throw new \RuntimeException('Zwischendatei nicht beschreibbar: ' . $zielDatei);
            }
            @chmod($zielDatei, 0600);
            stream_copy_to_stream($stream, $ziel);
            fclose($stream);
            fclose($ziel);
        } finally {
            $zip->close();
        }
    }

    /**
     * The server key of a config.php that travelled with the backup, or null
     * when there is none or it is not a valid key. Only this one value is
     * taken over: database credentials come from the installer form, and
     * the cron token is generated fresh.
     */
    public function serverSchluesselAusZip(string $zipPfad): ?string
    {
        $zip = $this->open($zipPfad);
        try {
            $config = $zip->getFromName('config.php');
        } finally {
            $zip->close();
        }
        if ($config === false) {
            return null;
        }

        if (preg_match("/'server_key'\\s*=>\\s*'([A-Za-z0-9+\\/=]+)'/", $config, $treffer) !== 1) {
            return null;
        }
        $roh = base64_decode($treffer[1], true);

        return $roh !== false && strlen($roh) === ConfigWriter::SERVER_KEY_BYTES ? $treffer[1] : null;
    }

    /**
     * @return array{app_version?: string, schema_version?: int, erstellt_am?: string, config_enthalten?: bool}
     */
    public function manifestAusZip(string $zipPfad): array
    {
        $zip = $this->open($zipPfad);
        try {
            $json = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }
        $daten = $json === false ? null : json_decode($json, true);

        return is_array($daten) ? $daten : [];
    }

    /**
     * Runs one block of statements and returns the new offset.
     *
     * @return array{offset: int, gesamt: int}
     */
    public function anwenden(\PDO $pdo, string $dumpDatei, int $offset): array
    {
        $dump = file_get_contents($dumpDatei);
        if ($dump === false) {
            throw new \RuntimeException('dump.sql nicht lesbar.');
        }

        $statements = SqlSplitter::split($dump);
        $block = array_slice($statements, $offset, self::STATEMENTS_PER_STEP);
        foreach ($block as $statement) {
            $pdo->exec($statement);
        }

        return ['offset' => $offset + count($block), 'gesamt' => count($statements)];
    }

    private function open(string $zipPfad): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPfad) !== true || $zip->locateName('dump.sql') === false) {
            throw new \RuntimeException('Das ZIP ist kein gültiges Backup (dump.sql fehlt).');
        }

        return $zip;
    }
}
