<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * Reads the ZIP of the archive import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3) straight from its upload
 * chunks - in pure PHP on ext-zlib, without ZipArchive.
 *
 * Why not ZipArchive: it wants one file on disk, and the upload is a row of
 * 2 MiB chunks. Joining them would put a second plaintext copy of the whole
 * archive into `shared/var/tmp`; encrypting the ZIP as a blob first does not
 * help either, because a secretstream blob can only be read from its start
 * and every step would decrypt the archive up to its entry again. This
 * reader seeks: the central directory at the end, then each entry's bytes,
 * unpacked in pieces and handed on - nothing is written anywhere.
 *
 * What it takes (and what Windows' "compressed folder", macOS' Archive
 * Utility, 7-Zip and our own App\Service\Export\ZipStrom write):
 * - methods 0 (stored) and 8 (deflate), with or without data descriptor -
 *   sizes and checksum always come from the central directory;
 * - names in UTF-8 (flag bit 11, or valid UTF-8 without the flag as macOS
 *   writes it), else CP850 (German Windows), or the Info-ZIP Unicode Path
 *   field 0x7075 when its checksum fits; then NFC, so "Müller" from macOS
 *   (decomposed) is the same name as "Müller" from Windows.
 *
 * What it refuses: ZIP64 and split archives as a whole (UngueltigesZip, the
 * page asks for several smaller archives); per entry an encrypted file, an
 * unknown method, a path with `..` or a drive letter - the entry gets a
 * `problem` and the import skips it. A path is only ever used as text, but
 * a ZIP is an upload and gets no benefit of the doubt (06 section 2).
 *
 * Every entry is checked against its CRC-32 and its announced size while it
 * is unpacked; more than announced or more than MAX_DATEI stops it at once,
 * so a ZIP bomb costs a few kilobytes of memory, not the request.
 */
final readonly class ZipLeser
{
    /** Largest file taken from the archive - the same bound as one upload (UploadService::MAX_FILE_BYTES). */
    public const int MAX_DATEI = 32 * 1024 * 1024;

    /** More entries than a club has receipts in a decade. */
    public const int MAX_EINTRAEGE = 20000;

    /** Largest central directory read into memory. */
    private const int MAX_VERZEICHNIS = 16 * 1024 * 1024;

    /** Read from the source in pieces of this size. */
    private const int LESE_BYTES = 65536;

    /** Fed to inflate in slices of this size: 8 KiB unpack to at most ~8 MiB. */
    private const int INFLATE_BYTES = 8192;

    private const int SIG_ENDE = 0x06054B50;
    private const int SIG_ZIP64_LOCATOR = 0x07064B50;
    private const int SIG_ZENTRAL = 0x02014B50;
    private const int SIG_LOKAL = 0x04034B50;

    /** Combining marks after a base letter, for systems without ext-intl. */
    private const array NFC = [
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü', "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
        "e\u{0301}" => 'é', "e\u{0300}" => 'è', "e\u{0302}" => 'ê', "a\u{0301}" => 'á', "a\u{0300}" => 'à', "a\u{0302}" => 'â',
        "o\u{0301}" => 'ó', "o\u{0302}" => 'ô', "i\u{0301}" => 'í', "u\u{0301}" => 'ú', "c\u{0327}" => 'ç', "n\u{0303}" => 'ñ',
        "E\u{0301}" => 'É', "a\u{030A}" => 'å', "A\u{030A}" => 'Å', "e\u{0308}" => 'ë', "i\u{0308}" => 'ï',
    ];

    public function __construct(private ByteQuelle $quelle)
    {
    }

    /**
     * The central directory, in archive order.
     *
     * @return list<ZipEintrag>
     * @throws UngueltigesZip
     */
    public function verzeichnis(): array
    {
        [$offset, $groesse, $anzahl] = $this->ende();
        if ($anzahl > self::MAX_EINTRAEGE) {
            throw new UngueltigesZip(sprintf('Das ZIP enthält mehr als %d Dateien – bitte in mehreren Teilen hochladen.', self::MAX_EINTRAEGE));
        }

        $daten = $this->quelle->lesen($offset, $groesse);
        if (strlen($daten) !== $groesse) {
            throw new UngueltigesZip('Das Inhaltsverzeichnis des ZIP ist unvollständig.');
        }

        $eintraege = [];
        $pos = 0;
        for ($i = 0; $i < $anzahl; $i++) {
            if ($pos + 46 > $groesse) {
                throw new UngueltigesZip('Das Inhaltsverzeichnis des ZIP ist beschädigt.');
            }
            $z = self::werte('Vsignatur/vversion/vbraucht/vflags/vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vgroesse/vname/vextra/vkommentar/vdisk/vintern/Vextern/Voffset', substr($daten, $pos, 46));
            if ($z['signatur'] !== self::SIG_ZENTRAL || $pos + 46 + $z['name'] + $z['extra'] + $z['kommentar'] > $groesse) {
                throw new UngueltigesZip('Das Inhaltsverzeichnis des ZIP ist beschädigt.');
            }
            $rohName = substr($daten, $pos + 46, $z['name']);
            $extra = substr($daten, $pos + 46 + $z['name'], $z['extra']);
            $pos += 46 + $z['name'] + $z['extra'] + $z['kommentar'];

            $eintraege[] = $this->eintrag($i, $z, $rohName, $extra);
        }

        return $eintraege;
    }

    /**
     * The unpacked content of one entry, in pieces - to be handed straight
     * to App\Service\Storage\BlobService::store(). The checksum is verified
     * at the end: a broken entry throws there, and the blob service throws
     * its draft away.
     *
     * @return \Generator<int, string>
     * @throws ZipEintragDefekt
     * @throws UngueltigesZip   the bytes are gone
     */
    public function inhalt(ZipEintrag $eintrag, int $max = self::MAX_DATEI): \Generator
    {
        if ($eintrag->problem !== null || $eintrag->ordner) {
            throw new ZipEintragDefekt($eintrag->problem ?? 'Ein Ordner hat keinen Inhalt.');
        }
        if ($eintrag->groesse > $max) {
            throw new ZipEintragDefekt('Die Datei ist zu groß.');
        }

        $kopf = $this->quelle->lesen($eintrag->offset, 30);
        if (strlen($kopf) !== 30) {
            throw new ZipEintragDefekt('Die Datei fehlt im ZIP.');
        }
        $l = self::werte('Vsignatur/vbraucht/vflags/vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vgroesse/vname/vextra', $kopf);
        if ($l['signatur'] !== self::SIG_LOKAL) {
            throw new ZipEintragDefekt('Die Datei ist im ZIP beschädigt.');
        }

        $start = $eintrag->offset + 30 + $l['name'] + $l['extra'];
        $ende = $start + $eintrag->gepackt;
        if ($ende > $this->quelle->laenge()) {
            throw new ZipEintragDefekt('Die Datei ist im ZIP abgeschnitten.');
        }

        $crc = hash_init('crc32b');
        $geschrieben = 0;
        $inflate = $eintrag->methode === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        if ($inflate === false) {
            throw new \RuntimeException('Cannot initialise inflate.');
        }

        $pos = $start;
        while ($pos < $ende) {
            $stueck = $this->quelle->lesen($pos, min(self::LESE_BYTES, $ende - $pos));
            if ($stueck === '') {
                throw new ZipEintragDefekt('Die Datei ist im ZIP abgeschnitten.');
            }
            $pos += strlen($stueck);
            $letztes = $pos >= $ende;

            if ($inflate === null) {
                $ausgabe = [$stueck];
            } else {
                $ausgabe = [];
                $bisher = $geschrieben;
                $scheiben = str_split($stueck, self::INFLATE_BYTES);
                foreach ($scheiben as $nr => $scheibe) {
                    $roh = @inflate_add($inflate, $scheibe, $letztes && $nr === array_key_last($scheiben) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                    if ($roh === false) {
                        throw new ZipEintragDefekt('Die Datei ist im ZIP beschädigt.');
                    }
                    // Checked per slice, so an inflating bomb never piles up.
                    $bisher += strlen($roh);
                    if ($bisher > $eintrag->groesse || $bisher > $max) {
                        throw new ZipEintragDefekt('Die Datei ist größer als im ZIP angegeben.');
                    }
                    $ausgabe[] = $roh;
                }
            }

            foreach ($ausgabe as $teil) {
                if ($teil === '') {
                    continue;
                }
                $geschrieben += strlen($teil);
                if ($geschrieben > $eintrag->groesse || $geschrieben > $max) {
                    throw new ZipEintragDefekt('Die Datei ist größer als im ZIP angegeben.');
                }
                hash_update($crc, $teil);

                yield $teil;
            }
        }

        if ($inflate !== null && inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw new ZipEintragDefekt('Die Datei ist im ZIP beschädigt.');
        }
        if ($geschrieben !== $eintrag->groesse) {
            throw new ZipEintragDefekt('Die Datei ist im ZIP unvollständig.');
        }
        if (hash_final($crc) !== sprintf('%08x', $eintrag->crc32)) {
            throw new ZipEintragDefekt('Die Prüfsumme der Datei stimmt nicht – das ZIP ist beschädigt.');
        }
    }

    /**
     * NFC for a UTF-8 string: with ext-intl the real thing, without it the
     * combining marks of German and the common Western European letters.
     */
    public static function nfc(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);

            return is_string($normal) ? $normal : $text;
        }

        return strtr($text, self::NFC);
    }

    /**
     * Finds the end of central directory record and checks the archive is
     * one we take.
     *
     * @return array{0: int, 1: int, 2: int} offset and size of the central
     *         directory, number of entries
     * @throws UngueltigesZip
     */
    private function ende(): array
    {
        $laenge = $this->quelle->laenge();
        if ($laenge < 22) {
            throw new UngueltigesZip('Die Datei ist kein ZIP-Archiv.');
        }

        // The record is 22 bytes plus a comment of at most 65 535.
        $suchStart = max(0, $laenge - 22 - 65535);
        $schwanz = $this->quelle->lesen($suchStart, $laenge - $suchStart);
        // From the back: a comment may itself contain the signature bytes.
        $e = null;
        $pos = strrpos($schwanz, pack('V', self::SIG_ENDE));
        while ($pos !== false) {
            $satz = substr($schwanz, $pos, 22);
            $kandidat = self::werte('Vsignatur/vdisk/vdiskVerzeichnis/vanzahlHier/vanzahl/Vgroesse/Voffset/vkommentar', $satz);
            if (strlen($satz) === 22 && $pos + 22 + $kandidat['kommentar'] <= strlen($schwanz)) {
                $e = $kandidat;

                break;
            }
            $pos = $pos === 0 ? false : strrpos(substr($schwanz, 0, $pos), pack('V', self::SIG_ENDE));
        }
        if ($e === null || $pos === false) {
            throw new UngueltigesZip('Die Datei ist kein ZIP-Archiv.');
        }

        $endePos = $suchStart + $pos;
        if ($endePos >= 20 && self::werte('Vsignatur', $this->quelle->lesen($endePos - 20, 4))['signatur'] === self::SIG_ZIP64_LOCATOR) {
            throw new UngueltigesZip('Das ZIP ist im ZIP64-Format (über 4 GB oder über 65 535 Dateien) – bitte in mehreren kleineren Teilen hochladen.');
        }
        if ($e['disk'] !== 0 || $e['diskVerzeichnis'] !== 0 || $e['anzahlHier'] !== $e['anzahl']) {
            throw new UngueltigesZip('Mehrteilige ZIP-Archive werden nicht unterstützt – bitte als ein Archiv packen.');
        }
        if ($e['anzahl'] === 0xFFFF || $e['groesse'] === 0xFFFFFFFF || $e['offset'] === 0xFFFFFFFF) {
            throw new UngueltigesZip('Das ZIP ist im ZIP64-Format (über 4 GB oder über 65 535 Dateien) – bitte in mehreren kleineren Teilen hochladen.');
        }
        if ($e['offset'] + $e['groesse'] > $endePos || $e['groesse'] > self::MAX_VERZEICHNIS) {
            throw new UngueltigesZip('Das Inhaltsverzeichnis des ZIP ist beschädigt.');
        }

        return [$e['offset'], $e['groesse'], $e['anzahl']];
    }

    /**
     * @param array<string, int> $z the fixed fields of the central directory entry
     */
    private function eintrag(int $index, array $z, string $rohName, string $extra): ZipEintrag
    {
        $roh = str_replace('\\', '/', self::name($rohName, $z['flags'], $extra));
        $ordner = str_ends_with($roh, '/');

        $problem = null;
        $teile = [];
        foreach (explode('/', $roh) as $teil) {
            $teil = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $teil));
            if ($teil === '' || $teil === '.') {
                continue;
            }
            if ($teil === '..') {
                $problem = 'Der Pfad zeigt aus dem Archiv heraus („..“).';
            }
            $teile[] = $teil;
        }
        if (str_starts_with($roh, '/') || preg_match('/^[A-Za-z]:/', $roh) === 1) {
            $problem = 'Der Pfad ist absolut.';
        }
        if ($teile === []) {
            $ordner = true;
        }

        if ($problem === null && !$ordner) {
            $problem = match (true) {
                ($z['flags'] & 0x1) !== 0 => 'Die Datei ist verschlüsselt.',
                $z['methode'] !== 0 && $z['methode'] !== 8 => 'Die Datei ist mit einem nicht unterstützten Verfahren gepackt.',
                $z['gepackt'] === 0xFFFFFFFF || $z['groesse'] === 0xFFFFFFFF || $z['offset'] === 0xFFFFFFFF => 'Die Datei ist zu groß.',
                default => null,
            };
        }

        return new ZipEintrag(
            index: $index,
            pfad: implode('/', $teile),
            ordner: $ordner,
            methode: $z['methode'],
            crc32: $z['crc'],
            gepackt: $z['gepackt'],
            groesse: $z['groesse'],
            offset: $z['offset'],
            problem: $problem,
        );
    }

    /**
     * The entry name as UTF-8 in NFC.
     */
    private static function name(string $roh, int $flags, string $extra): string
    {
        if (($flags & 0x800) !== 0) {
            $name = mb_scrub($roh, 'UTF-8');
        } else {
            $name = self::unicodePfad($roh, $extra)
                ?? (mb_check_encoding($roh, 'UTF-8') ? $roh : mb_convert_encoding($roh, 'UTF-8', 'CP850'));
        }

        return self::nfc($name);
    }

    /**
     * The Info-ZIP Unicode Path extra field (0x7075): version 1, the CRC-32
     * of the raw name it stands for, the name in UTF-8. Only taken when the
     * checksum fits - a tool that renamed the entry without updating the
     * field would otherwise win with a stale name.
     */
    private static function unicodePfad(string $roh, string $extra): ?string
    {
        $pos = 0;
        while ($pos + 4 <= strlen($extra)) {
            ['id' => $id, 'laenge' => $laenge] = self::werte('vid/vlaenge', substr($extra, $pos, 4));
            $daten = substr($extra, $pos + 4, $laenge);
            $pos += 4 + $laenge;
            if ($id !== 0x7075 || strlen($daten) < 5 || ord($daten[0]) !== 1) {
                continue;
            }

            $crc = self::werte('Vcrc', substr($daten, 1, 4))['crc'];
            $name = substr($daten, 5);
            if ($crc === crc32($roh) && mb_check_encoding($name, 'UTF-8')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * unpack() with every field present - a short read gives zeros, the
     * signature checks around it then refuse the record.
     *
     * @return array<string, int>
     */
    private static function werte(string $format, string $bytes): array
    {
        $groesse = 0;
        foreach (explode('/', $format) as $feld) {
            $groesse += $feld[0] === 'V' ? 4 : 2;
        }
        $werte = unpack($format, str_pad($bytes, $groesse, "\0"));

        return is_array($werte) ? array_map(intval(...), $werte) : [];
    }
}
