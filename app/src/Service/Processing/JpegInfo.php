<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * The handful of facts App\Service\Processing\PdfAusBildern needs out of a
 * JPEG's own header, read without decoding a single pixel
 * (docs/spec/03-erfassung-und-ki.md section 3): pixel size, how many colour
 * components it has, whether it is an Adobe-style inverted CMYK, and the
 * EXIF orientation a camera wrote into it.
 *
 * Hand-written instead of ext-exif: exif_read_data() only understands JFIF/
 * Exif JPEGs and TIFF, is a second parser for what is already right here in
 * the byte stream, and CLAUDE.md section 1 keeps native dependencies to what
 * composer.json already requires (ext-gd, not ext-exif).
 */
final readonly class JpegInfo
{
    private const int SOI = 0xD8;
    private const int EOI = 0xD9;
    private const int SOS = 0xDA;
    private const int APP1 = 0xE1;
    private const int APP14 = 0xEE;

    /** SOF0..SOF15 except the four DHT/JPG/DAC/DNL markers that share the range. */
    private const array SOF_MARKER = [
        0xC0 => true, 0xC1 => true, 0xC2 => true, 0xC3 => true,
        0xC5 => true, 0xC6 => true, 0xC7 => true,
        0xC9 => true, 0xCA => true, 0xCB => true,
        0xCD => true, 0xCE => true, 0xCF => true,
    ];

    private function __construct(
        public int $width,
        public int $height,
        public int $components,
        public bool $cmykInvertiert,
        public int $orientierung,
    ) {
    }

    public static function aus(string $jpeg): self
    {
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            throw new ProcessingException('Keine JPEG-Datei.');
        }

        $length = strlen($jpeg);
        $pos = 2;
        $groesse = null;
        $components = null;
        $adobeMarker = false;
        $orientierung = 1;

        while ($pos < $length) {
            if ($jpeg[$pos] !== "\xFF") {
                throw new ProcessingException('JPEG-Marker beschädigt.');
            }
            while ($pos < $length && $jpeg[$pos] === "\xFF") {
                $pos++;
            }
            if ($pos >= $length) {
                break;
            }
            $marker = ord($jpeg[$pos]);
            $pos++;

            // SOI, TEM (0x01) and the restart markers RST0..RST7 carry no length.
            if ($marker === self::SOI || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($marker === self::EOI) {
                break;
            }
            if ($pos + 2 > $length) {
                throw new ProcessingException('JPEG-Segmentlänge fehlt.');
            }

            $segLen = (ord($jpeg[$pos]) << 8) | ord($jpeg[$pos + 1]);
            if ($segLen < 2 || $pos + $segLen > $length) {
                throw new ProcessingException('JPEG-Segmentlänge ungültig.');
            }
            $data = substr($jpeg, $pos + 2, $segLen - 2);

            if (isset(self::SOF_MARKER[$marker])) {
                if (strlen($data) < 6) {
                    throw new ProcessingException('JPEG-SOF-Segment zu kurz.');
                }
                $groesse = [
                    (ord($data[3]) << 8) | ord($data[4]),
                    (ord($data[1]) << 8) | ord($data[2]),
                ];
                $components = ord($data[5]);
            } elseif ($marker === self::APP14 && str_starts_with($data, 'Adobe')) {
                $adobeMarker = true;
            } elseif ($marker === self::APP1 && str_starts_with($data, "Exif\0\0")) {
                $orientierung = self::exifOrientierung(substr($data, 6)) ?? 1;
            }

            $pos += $segLen;

            // Entropy-coded scan data follows SOS, with no further markers to
            // parse (restart markers inside it were already handled above).
            if ($marker === self::SOS) {
                break;
            }
        }

        if ($groesse === null || $components === null) {
            throw new ProcessingException('JPEG ohne Bildgrößen-Segment.');
        }

        return new self(
            width: $groesse[0],
            height: $groesse[1],
            components: $components,
            cmykInvertiert: $adobeMarker && $components === 4,
            orientierung: $orientierung,
        );
    }

    /** Orientation tag 0x0112 of IFD0, 1 (normal) when absent or unreadable. */
    private static function exifOrientierung(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $byteOrder = substr($tiff, 0, 2);
        if ($byteOrder === 'II') {
            $little = true;
        } elseif ($byteOrder === 'MM') {
            $little = false;
        } else {
            return null;
        }

        $u16 = static fn (int $off): int => $little
            ? ord($tiff[$off]) | (ord($tiff[$off + 1]) << 8)
            : (ord($tiff[$off]) << 8) | ord($tiff[$off + 1]);
        $u32 = static fn (int $off): int => $little
            ? ord($tiff[$off]) | (ord($tiff[$off + 1]) << 8) | (ord($tiff[$off + 2]) << 16) | (ord($tiff[$off + 3]) << 24)
            : (ord($tiff[$off]) << 24) | (ord($tiff[$off + 1]) << 16) | (ord($tiff[$off + 2]) << 8) | ord($tiff[$off + 3]);

        $ifdOffset = $u32(4);
        if ($ifdOffset < 0 || $ifdOffset + 2 > strlen($tiff)) {
            return null;
        }

        $count = $u16($ifdOffset);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifdOffset + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if ($u16($entry) === 0x0112) {
                $wert = $u16($entry + 8);

                return $wert >= 1 && $wert <= 8 ? $wert : null;
            }
        }

        return null;
    }
}
