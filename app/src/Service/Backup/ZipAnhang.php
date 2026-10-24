<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Builds a ZIP archive piece by piece across several short requests
 * (issue #112, docs/spec/06-betrieb.md section 2): the admin page's backup
 * step chain appends entries to a work file, request after request, and only
 * the last step writes the central directory.
 *
 * Why not ZipArchive: libzip rewrites the whole archive on every close(), so
 * adding the blob files in portions would copy gigabytes again with every
 * portion. Appending raw entries costs only what is added.
 *
 * Always ZIP64 (zip64 extra field in every header, zip64 end records): an
 * archive of all receipt files may exceed 4 GiB, and one code path is easier
 * to trust than two. ZipArchive, Python's zipfile and 7-Zip read it.
 *
 * Each entry: local header with the CRC and sizes left open, the data, then
 * the CRC and sizes patched into that header - so the data is read only
 * once. The central directory records go to a second work file and are
 * copied behind the entries at the end.
 *
 * Retrying a step is safe as long as the caller cuts both work files back to
 * the lengths it last committed (BackupErstellung does) - everything written
 * after that is simply overwritten.
 */
final class ZipAnhang
{
    private const int SIGNATUR_LOKAL = 0x04034B50;
    private const int SIGNATUR_ZENTRAL = 0x02014B50;
    private const int SIGNATUR_ZIP64_ENDE = 0x06064B50;
    private const int SIGNATUR_ZIP64_LOKATOR = 0x07064B50;
    private const int SIGNATUR_ENDE = 0x06054B50;

    /** 4.5: ZIP64. */
    private const int VERSION = 45;

    /** Made by Unix (3), so the external attributes below count. */
    private const int VERSION_ERSTELLT = (3 << 8) | self::VERSION;

    /** Bit 11: names are UTF-8. */
    private const int FLAGS = 0x0800;

    public const int STORE = 0;
    public const int DEFLATE = 8;

    /** A regular file, rw------- (Unix, high half). */
    private const int ATTRIBUTE = 0o100600 << 16;

    private const int UNBEKANNT_32 = 0xFFFFFFFF;
    private const int UNBEKANNT_16 = 0xFFFF;

    /** Bytes per read. */
    private const int BLOCK = 1024 * 1024;

    private readonly int $dosZeit;

    private readonly int $dosDatum;

    /**
     * @param resource $archiv work file of the archive, opened for reading
     *        and writing, positioned at its committed end
     * @param resource $zentral work file of the central directory records,
     *        positioned at its committed end
     */
    public function __construct(
        private $archiv,
        private $zentral,
        \DateTimeImmutable $zeitpunkt,
    ) {
        $jahr = max(1980, min(2107, (int) $zeitpunkt->format('Y')));
        $this->dosZeit = ((int) $zeitpunkt->format('G') << 11) | ((int) $zeitpunkt->format('i') << 5) | intdiv((int) $zeitpunkt->format('s'), 2);
        $this->dosDatum = (($jahr - 1980) << 9) | ((int) $zeitpunkt->format('n') << 5) | (int) $zeitpunkt->format('j');
    }

    /**
     * Appends one entry read from $quelle until its end.
     *
     * @param resource $quelle
     * @param int $stufe deflate level for DEFLATE, ignored for STORE
     *
     * @return int bytes of the original content
     */
    public function eintrag(string $name, $quelle, int $methode = self::STORE, int $stufe = 1): int
    {
        $offen = $this->eintragBeginnen($name, $methode);
        $offen = $this->eintragFortsetzen($offen, $quelle, $stufe);
        $this->eintragBeenden($offen);

        return (int) $offen['roh'];
    }

    /**
     * Starts an entry whose content follows in several requests (issue
     * #208: the database dump of a large archive does not fit into one).
     * Writes the local header and returns the open entry as JSON-safe data
     * for eintragFortsetzen()/eintragBeenden() - the caller keeps it with
     * the committed lengths of the work files, so a step that died is run
     * again from the same data.
     *
     * @return array<string, mixed>
     */
    public function eintragBeginnen(string $name, int $methode = self::STORE): array
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true)) {
            throw new \InvalidArgumentException('Invalid entry name.');
        }
        if ($methode !== self::STORE && $methode !== self::DEFLATE) {
            throw new \InvalidArgumentException('Unsupported compression method.');
        }
        $offset = $this->position();
        $this->schreibe($this->archiv, pack(
            'VvvvvvVVVvv',
            self::SIGNATUR_LOKAL,
            self::VERSION,
            self::FLAGS,
            $methode,
            $this->dosZeit,
            $this->dosDatum,
            0,
            self::UNBEKANNT_32,
            self::UNBEKANNT_32,
            strlen($name),
            20,
        ) . $name . pack('vvPP', 0x0001, 16, 0, 0));

        return [
            'name' => $name,
            'methode' => $methode,
            'offset' => $offset,
            'crc' => base64_encode(serialize(hash_init('crc32b'))),
            'roh' => 0,
            'gepackt' => 0,
        ];
    }

    /**
     * Appends $quelle (until its end) to an open entry.
     *
     * With DEFLATE every call is a deflate stream of its own that ends in a
     * sync flush - byte-aligned blocks without the final bit, so the pieces
     * of all calls form one valid deflate stream that eintragBeenden()
     * closes with a final empty block. The CRC runs on across calls as a
     * serialized hash context.
     *
     * @param array<string, mixed> $offen
     * @param resource $quelle
     * @return array<string, mixed> the open entry after this piece
     */
    public function eintragFortsetzen(array $offen, $quelle, int $stufe = 1): array
    {
        $crc = $this->crcKontext($offen);
        $deflate = null;
        if ($offen['methode'] === self::DEFLATE) {
            $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => max(1, min(9, $stufe))]);
            if ($deflate === false) {
                throw new \RuntimeException('deflate_init failed.');
            }
        }
        $roh = (int) $offen['roh'];
        $gepackt = (int) $offen['gepackt'];
        // The last block is held back: deflate_add() returns nothing for a
        // flush without data, so the sync flush rides on the last block.
        $vorrat = null;
        while (!feof($quelle)) {
            $block = fread($quelle, self::BLOCK);
            if ($block === false) {
                throw new \RuntimeException('Backup source could not be read.');
            }
            if ($block === '') {
                continue;
            }
            hash_update($crc, $block);
            $roh += strlen($block);
            if ($deflate === null) {
                $gepackt += strlen($block);
                $this->schreibe($this->archiv, $block);
                continue;
            }
            if ($vorrat !== null) {
                $gepackt += $this->packe($deflate, $vorrat, ZLIB_NO_FLUSH);
            }
            $vorrat = $block;
        }
        if ($deflate !== null && $vorrat !== null) {
            $gepackt += $this->packe($deflate, $vorrat, ZLIB_SYNC_FLUSH);
        }

        return [
            ...$offen,
            'crc' => base64_encode(serialize($crc)),
            'roh' => $roh,
            'gepackt' => $gepackt,
        ];
    }

    /**
     * Closes an open entry: the final deflate block, CRC and sizes patched
     * into its local header, its central directory record.
     *
     * @param array<string, mixed> $offen
     */
    public function eintragBeenden(array $offen): void
    {
        $name = (string) $offen['name'];
        $methode = (int) $offen['methode'];
        $offset = (int) $offen['offset'];
        $roh = (int) $offen['roh'];
        $gepackt = (int) $offen['gepackt'];
        if ($methode === self::DEFLATE) {
            $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 1]);
            $schluss = $deflate === false ? false : deflate_add($deflate, '', ZLIB_FINISH);
            if ($schluss === false) {
                throw new \RuntimeException('deflate_add failed.');
            }
            $gepackt += strlen($schluss);
            $this->schreibe($this->archiv, $schluss);
        }
        $pruefsumme = (int) hexdec(hash_final($this->crcKontext($offen)));

        // Patch CRC and the zip64 sizes into the local header.
        $ende = $this->position();
        $this->springe($offset + 14);
        $this->schreibe($this->archiv, pack('V', $pruefsumme));
        $this->springe($offset + 30 + strlen($name) + 4);
        $this->schreibe($this->archiv, pack('PP', $roh, $gepackt));
        $this->springe($ende);

        $this->schreibe($this->zentral, pack(
            'VvvvvvvVVVvvvvvVV',
            self::SIGNATUR_ZENTRAL,
            self::VERSION_ERSTELLT,
            self::VERSION,
            self::FLAGS,
            $methode,
            $this->dosZeit,
            $this->dosDatum,
            $pruefsumme,
            self::UNBEKANNT_32,
            self::UNBEKANNT_32,
            strlen($name),
            28,
            0,
            0,
            0,
            self::ATTRIBUTE,
            self::UNBEKANNT_32,
        ) . $name . pack('vvPPP', 0x0001, 24, $roh, $gepackt, $offset));
    }

    /**
     * @return int bytes written
     */
    private function packe(\DeflateContext $deflate, string $daten, int $flush): int
    {
        $gepackt = deflate_add($deflate, $daten, $flush);
        if ($gepackt === false) {
            throw new \RuntimeException('deflate_add failed.');
        }
        $this->schreibe($this->archiv, $gepackt);

        return strlen($gepackt);
    }

    /**
     * @param array<string, mixed> $offen
     */
    private function crcKontext(array $offen): \HashContext
    {
        $kontext = unserialize(
            (string) base64_decode((string) ($offen['crc'] ?? ''), true),
            ['allowed_classes' => [\HashContext::class]],
        );
        if (!$kontext instanceof \HashContext) {
            throw new \RuntimeException('Die Arbeitsdatei des Backups ist beschädigt.');
        }

        return $kontext;
    }

    /**
     * Appends one entry from a string.
     */
    public function eintragAusText(string $name, string $inhalt): void
    {
        $quelle = fopen('php://memory', 'w+b');
        if ($quelle === false) {
            throw new \RuntimeException('php://memory could not be opened.');
        }
        try {
            fwrite($quelle, $inhalt);
            rewind($quelle);
            $this->eintrag($name, $quelle);
        } finally {
            fclose($quelle);
        }
    }

    /**
     * Copies the central directory behind the entries and writes the end
     * records. After this the archive is complete.
     *
     * @param int $anzahl entries written in all requests together
     */
    public function abschliessen(int $anzahl): void
    {
        $zentralOffset = $this->position();
        if (!rewind($this->zentral)) {
            throw new \RuntimeException('Central directory work file could not be read.');
        }
        $zentralGroesse = 0;
        while (!feof($this->zentral)) {
            $block = fread($this->zentral, self::BLOCK);
            if ($block === false) {
                throw new \RuntimeException('Central directory work file could not be read.');
            }
            $zentralGroesse += strlen($block);
            $this->schreibe($this->archiv, $block);
        }

        $zip64Ende = $this->position();
        $this->schreibe($this->archiv, pack(
            'VPvvVVPPPP',
            self::SIGNATUR_ZIP64_ENDE,
            44,
            self::VERSION_ERSTELLT,
            self::VERSION,
            0,
            0,
            $anzahl,
            $anzahl,
            $zentralGroesse,
            $zentralOffset,
        ));
        $this->schreibe($this->archiv, pack('VVPV', self::SIGNATUR_ZIP64_LOKATOR, 0, $zip64Ende, 1));
        $this->schreibe($this->archiv, pack(
            'VvvvvVVv',
            self::SIGNATUR_ENDE,
            0,
            0,
            min($anzahl, self::UNBEKANNT_16),
            min($anzahl, self::UNBEKANNT_16),
            min($zentralGroesse, self::UNBEKANNT_32),
            min($zentralOffset, self::UNBEKANNT_32),
            0,
        ));
        if (!fflush($this->archiv)) {
            throw new \RuntimeException('Backup work file could not be written.');
        }
    }

    private function position(): int
    {
        $position = ftell($this->archiv);
        if ($position === false) {
            throw new \RuntimeException('Backup work file position unknown.');
        }

        return $position;
    }

    private function springe(int $position): void
    {
        if (fseek($this->archiv, $position) !== 0) {
            throw new \RuntimeException('Backup work file could not be positioned.');
        }
    }

    /**
     * @param resource $ziel
     */
    private function schreibe($ziel, string $daten): void
    {
        $laenge = strlen($daten);
        $geschrieben = 0;
        while ($geschrieben < $laenge) {
            $n = fwrite($ziel, $geschrieben === 0 ? $daten : substr($daten, $geschrieben));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Backup work file could not be written (disk full?).');
            }
            $geschrieben += $n;
        }
    }
}
