<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * Writes a ZIP archive as a stream of pieces (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md section 2): every file goes out while
 * it is being read, nothing is collected first and nothing is written to disk
 * - the plaintext of a receipt must never become a file on the server, and a
 * year of scans does not fit into the memory limit.
 *
 * Plain PHP on ext-zlib, no library (decision in issue #76): each entry is a
 * local header without sizes (general purpose flag bit 3), the raw deflate
 * stream, and a data descriptor with the CRC-32 and both sizes computed on
 * the way. Deflate rather than "stored": a deflate stream ends by itself, so
 * every unzipper finds the descriptor - with "stored" and bit 3, streaming
 * readers cannot tell where the data ends. Names are UTF-8 (flag bit 11), so
 * umlauts survive on Windows, macOS and Linux.
 *
 * No ZIP64: a club's year stays far below 4 GiB and 65 535 files. Rather
 * than writing a broken archive, the writer throws App\Service\Export\
 * ZipZuGross when a size, an offset or the number of entries would not fit;
 * App\Service\Export\ZipExport checks the limits before the first byte.
 *
 * Usage: `yield from $zip->datei($pfad, $stuecke)` per file, then
 * `yield $zip->abschluss()`. One instance per archive.
 */
final class ZipStrom
{
    /** Entries the classic end-of-central-directory record can count. */
    public const int MAX_EINTRAEGE = 0xFFFF;

    /** Sizes and offsets of the classic (non-ZIP64) records. */
    public const int MAX_BYTES = 0xFFFFFFFF;

    private const int SIGNATUR_LOKAL = 0x04034B50;
    private const int SIGNATUR_DESKRIPTOR = 0x08074B50;
    private const int SIGNATUR_ZENTRAL = 0x02014B50;
    private const int SIGNATUR_ENDE = 0x06054B50;

    /** 2.0: deflate and data descriptors. */
    private const int VERSION = 20;

    /** Made by Unix (3) with 2.0, so the external attributes below count. */
    private const int VERSION_ERSTELLT = (3 << 8) | self::VERSION;

    /** Bit 3: sizes and CRC follow the data; bit 11: names are UTF-8. */
    private const int FLAGS = 0x0008 | 0x0800;

    private const int DEFLATE = 8;

    /** A regular file, rw-r--r--, in the high half (Unix). */
    private const int ATTRIBUTE = 0o100644 << 16;

    private readonly int $dosZeit;

    private readonly int $dosDatum;

    /** Bytes handed out so far: the offset of the next local header. */
    private int $offset = 0;

    /** @var list<string> the central directory records, in order */
    private array $zentral = [];

    /** @var array<string, true> names already written */
    private array $namen = [];

    private bool $abgeschlossen = false;

    /**
     * @param \DateTimeImmutable $zeitpunkt the modification time of every
     *        entry - the moment of the export, nothing about a receipt
     */
    public function __construct(\DateTimeImmutable $zeitpunkt)
    {
        // DOS time cannot go below 1980 or above 2107.
        $jahr = max(1980, min(2107, (int) $zeitpunkt->format('Y')));
        $this->dosZeit = ((int) $zeitpunkt->format('G') << 11) | ((int) $zeitpunkt->format('i') << 5) | intdiv((int) $zeitpunkt->format('s'), 2);
        $this->dosDatum = (($jahr - 1980) << 9) | ((int) $zeitpunkt->format('n') << 5) | (int) $zeitpunkt->format('j');
    }

    /**
     * One file: the local header at once, then the compressed data as the
     * source delivers it, then the data descriptor. The source is pulled
     * lazily - a generator decrypting block by block is never drained
     * before its first piece leaves.
     *
     * @param string $pfad path inside the archive, "/" between levels
     * @param iterable<string> $inhalt the file's content in pieces
     * @param int $stufe deflate level 0-9; low for content that is already
     *        compressed (PDF, JPEG), higher for text
     *
     * @return \Generator<int, string>
     *
     * @throws \InvalidArgumentException for a path that could leave the
     *         archive's folder or appears twice - a programming error
     * @throws ZipZuGross when the archive outgrows the classic ZIP limits
     */
    public function datei(string $pfad, iterable $inhalt, int $stufe = 6): \Generator
    {
        if ($this->abgeschlossen) {
            throw new \LogicException('The archive is already closed.');
        }
        self::pruefePfad($pfad);
        if (isset($this->namen[$pfad])) {
            throw new \InvalidArgumentException('Duplicate path in ZIP.');
        }
        if (count($this->zentral) >= self::MAX_EINTRAEGE) {
            throw new ZipZuGross('Too many files for one ZIP.');
        }
        $this->namen[$pfad] = true;

        $start = $this->offset;
        yield $this->ausgabe(pack(
            'VvvvvvVVVvv',
            self::SIGNATUR_LOKAL,
            self::VERSION,
            self::FLAGS,
            self::DEFLATE,
            $this->dosZeit,
            $this->dosDatum,
            0,
            0,
            0,
            strlen($pfad),
            0,
        ) . $pfad);

        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => max(0, min(9, $stufe))]);
        if ($deflate === false) {
            throw new \RuntimeException('deflate_init failed.');
        }
        $crc = hash_init('crc32b');
        $roh = 0;
        $gepackt = 0;

        foreach ($inhalt as $stueck) {
            if ($stueck === '') {
                continue;
            }
            hash_update($crc, $stueck);
            $roh += strlen($stueck);
            $daten = self::deflate($deflate, $stueck, ZLIB_NO_FLUSH);
            if ($daten !== '') {
                $gepackt += strlen($daten);
                yield $this->ausgabe($daten);
            }
        }

        $daten = self::deflate($deflate, '', ZLIB_FINISH);
        $gepackt += strlen($daten);
        if ($daten !== '') {
            yield $this->ausgabe($daten);
        }

        if ($roh > self::MAX_BYTES || $gepackt > self::MAX_BYTES) {
            throw new ZipZuGross('A file is too large for a ZIP without ZIP64.');
        }
        $pruefsumme = (int) hexdec(hash_final($crc));

        yield $this->ausgabe(pack('VVVV', self::SIGNATUR_DESKRIPTOR, $pruefsumme, $gepackt, $roh));

        $this->zentral[] = pack(
            'VvvvvvvVVVvvvvvVV',
            self::SIGNATUR_ZENTRAL,
            self::VERSION_ERSTELLT,
            self::VERSION,
            self::FLAGS,
            self::DEFLATE,
            $this->dosZeit,
            $this->dosDatum,
            $pruefsumme,
            $gepackt,
            $roh,
            strlen($pfad),
            0,
            0,
            0,
            0,
            self::ATTRIBUTE,
            $start,
        ) . $pfad;
    }

    /**
     * The central directory and the end record. Without this the archive
     * is unreadable - which is exactly what a stream broken off halfway
     * should be.
     *
     * @throws ZipZuGross
     */
    public function abschluss(): string
    {
        if ($this->abgeschlossen) {
            throw new \LogicException('The archive is already closed.');
        }
        $this->abgeschlossen = true;

        $verzeichnis = implode('', $this->zentral);
        $anfang = $this->offset;
        if ($anfang + strlen($verzeichnis) > self::MAX_BYTES) {
            throw new ZipZuGross('The ZIP is too large without ZIP64.');
        }
        $anzahl = count($this->zentral);

        return $this->ausgabe($verzeichnis . pack(
            'VvvvvVVv',
            self::SIGNATUR_ENDE,
            0,
            0,
            $anzahl,
            $anzahl,
            strlen($verzeichnis),
            $anfang,
            0,
        ));
    }

    /** Bytes handed out so far. */
    public function groesse(): int
    {
        return $this->offset;
    }

    /**
     * Counts what goes out; an offset the next local header could not
     * record any more ends the archive here.
     */
    private function ausgabe(string $bytes): string
    {
        $this->offset += strlen($bytes);
        if ($this->offset > self::MAX_BYTES) {
            throw new ZipZuGross('The ZIP is too large without ZIP64.');
        }

        return $bytes;
    }

    private static function deflate(\DeflateContext $deflate, string $daten, int $modus): string
    {
        $ergebnis = deflate_add($deflate, $daten, $modus);
        if ($ergebnis === false) {
            throw new \RuntimeException('deflate_add failed.');
        }

        return $ergebnis;
    }

    /**
     * Relative, "/"-separated, no level that is empty, "." or "..", no
     * backslash or control character - an entry can never land outside the
     * folder it is unpacked into.
     */
    private static function pruefePfad(string $pfad): void
    {
        if ($pfad === '' || strlen($pfad) > 0xFFFF || !mb_check_encoding($pfad, 'UTF-8')
            || str_contains($pfad, '\\') || preg_match('/[\x00-\x1F\x7F]/', $pfad) === 1
        ) {
            throw new \InvalidArgumentException('Invalid path in ZIP.');
        }
        foreach (explode('/', $pfad) as $ebene) {
            if ($ebene === '' || $ebene === '.' || $ebene === '..') {
                throw new \InvalidArgumentException('Invalid path in ZIP.');
            }
        }
    }
}
