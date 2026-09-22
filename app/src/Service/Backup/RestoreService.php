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
 * expression, never included, and the blob files are written under names
 * this application builds itself, never under the ones in the archive.
 */
final readonly class RestoreService
{
    /** Statements per request - no single request may run long (CLAUDE.md section 1). */
    public const int STATEMENTS_PER_STEP = 200;

    /** Blob files per request, for the same reason. */
    public const int BLOBS_PER_STEP = 200;

    /** ... and a byte budget on top: 200 scans are not 200 receipt lines. */
    public const int BLOB_BYTES_PER_STEP = 8 * 1024 * 1024;

    /**
     * Name of a blob inside the backup: blobs/<2 chars>/<random id>, the
     * layout of Service\Storage\FsBlobBackend and nothing else.
     */
    private const string BLOB_ENTRY = '#^' . BackupService::BLOB_PREFIX . '([0-9a-f]{2})/([0-9a-f]{32})$#';

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

    /**
     * Writes the next portion of blob files into `$zielDir` and returns the
     * new offset. Counting and budget are per request, so a backup with
     * thousands of receipts restores in short steps like the dump does.
     *
     * Nothing is unpacked with extractTo(): the ZIP is an upload, and the
     * only names that are written are the ones this application itself
     * produces (blobs/<2 chars>/<32 hex>). Everything else - a path with
     * "..", an absolute name, a backslash, a stray file - is counted and
     * dropped, never written.
     *
     * @return array{offset: int, gesamt: int, abgelehnt: int}
     */
    public function blobsAusZip(string $zipPfad, string $zielDir, int $offset): array
    {
        $zip = $this->open($zipPfad);
        try {
            $eintraege = $this->blobEintraege($zip);
            $gesamt = count($eintraege);
            $abgelehnt = 0;
            $bytes = 0;
            $getan = 0;

            for ($i = $offset; $i < $gesamt; $i++) {
                [$name, $groesse] = $eintraege[$i];
                $offset = $i + 1;

                if (preg_match(self::BLOB_ENTRY, $name, $treffer) !== 1
                    || $treffer[1] !== substr($treffer[2], 0, 2)
                ) {
                    $abgelehnt++;
                    continue;
                }

                $this->blobSchreiben($zip, $name, $zielDir . '/' . $treffer[1] . '/' . $treffer[2]);

                $getan++;
                $bytes += $groesse;
                if ($getan >= self::BLOBS_PER_STEP || $bytes >= self::BLOB_BYTES_PER_STEP) {
                    break;
                }
            }

            return ['offset' => $offset, 'gesamt' => $gesamt, 'abgelehnt' => $abgelehnt];
        } finally {
            $zip->close();
        }
    }

    /**
     * How many blob files the backup carries - only the central directory is
     * read, no content. Zero means the ZIP needs no blob phase at all.
     */
    public function blobAnzahlImZip(string $zipPfad): int
    {
        $zip = $this->open($zipPfad);
        try {
            return count($this->blobEintraege($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * Every entry below blobs/, in the order of the central directory - the
     * same order in every request, which is what makes the offset work.
     *
     * @return list<array{string, int}> name and ciphertext size
     */
    private function blobEintraege(\ZipArchive $zip): array
    {
        $eintraege = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || !str_starts_with((string) $stat['name'], BackupService::BLOB_PREFIX)) {
                continue;
            }
            $eintraege[] = [(string) $stat['name'], (int) $stat['size']];
        }

        return $eintraege;
    }

    private function blobSchreiben(\ZipArchive $zip, string $eintrag, string $zielDatei): void
    {
        $dir = dirname($zielDatei);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Blob-Verzeichnis kann nicht angelegt werden.');
        }

        $quelle = $zip->getStream($eintrag);
        if ($quelle === false) {
            throw new \RuntimeException('Blob aus dem Backup nicht lesbar.');
        }
        $ziel = fopen($zielDatei, 'wb');
        if ($ziel === false) {
            fclose($quelle);
            throw new \RuntimeException('Blob-Verzeichnis nicht beschreibbar: ' . $dir);
        }

        try {
            stream_copy_to_stream($quelle, $ziel);
        } finally {
            fclose($quelle);
            fclose($ziel);
        }
        @chmod($zielDatei, 0664);
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
