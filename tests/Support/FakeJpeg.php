<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Builds the smallest possible byte string App\Service\Processing\JpegInfo
 * (and, through it, App\Service\Processing\PdfAusBildern) accepts as a JPEG:
 * real markers, no real entropy-coded pixel data. Used wherever a test needs
 * exact control over width, height, component count, EXIF orientation or the
 * Adobe CMYK marker - things a real photo would only give indirectly.
 */
final class FakeJpeg
{
    public static function bauen(
        int $breite,
        int $hoehe,
        int $komponenten = 3,
        ?int $orientierung = null,
        bool $littleEndianExif = true,
        bool $adobe = false,
    ): string {
        $bytes = "\xFF\xD8";
        if ($orientierung !== null) {
            $bytes .= self::exifApp1($orientierung, $littleEndianExif);
        }
        if ($adobe) {
            $bytes .= self::segment(0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x00");
        }
        $bytes .= self::sof0($breite, $hoehe, $komponenten);
        $bytes .= self::segment(0xDA, "\x00\x00\x00");
        $bytes .= "\xFF\xD9";

        return $bytes;
    }

    private static function sof0(int $breite, int $hoehe, int $komponenten): string
    {
        $daten = chr(8) . pack('n', $hoehe) . pack('n', $breite) . chr($komponenten);
        for ($i = 1; $i <= $komponenten; $i++) {
            $daten .= chr($i) . chr(0x11) . chr(0);
        }

        return self::segment(0xC0, $daten);
    }

    private static function exifApp1(int $orientierung, bool $little): string
    {
        $order = $little ? 'II' : 'MM';
        $u16 = static fn (int $wert): string => $little ? pack('v', $wert) : pack('n', $wert);
        $u32 = static fn (int $wert): string => $little ? pack('V', $wert) : pack('N', $wert);

        $tiff = $order . $u16(42) . $u32(8);
        $tiff .= $u16(1);
        $tiff .= $u16(0x0112) . $u16(3) . $u32(1) . $u16($orientierung) . "\x00\x00";
        $tiff .= $u32(0);

        return self::segment(0xE1, "Exif\0\0" . $tiff);
    }

    private static function segment(int $marker, string $daten): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($daten) + 2) . $daten;
    }
}
