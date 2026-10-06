<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * Decodes the stream filters a text layer needs (ISO 32000-1 section 7.4):
 * FlateDecode (with the PNG/TIFF predictors of its DecodeParms),
 * ASCIIHexDecode and ASCII85Decode. Image filters (DCTDecode, JBIG2Decode,
 * CCITTFaxDecode, JPXDecode) and the rare LZW/RunLength throw PdfDefekt -
 * none of them carries text, and the caller only decodes content streams,
 * fonts' ToUnicode maps and object streams.
 *
 * Every call takes the number of bytes it may still produce: a small,
 * highly compressed stream must not expand into more memory than a
 * shared-hosting request has (CLAUDE.md section 1).
 */
final class PdfFilter
{
    private const int INFLATE_STUECK = 65536;

    /**
     * @param list<string> $filter names in order of application
     * @param list<array<string, mixed>> $parameter DecodeParms parallel to $filter
     */
    public static function dekodieren(string $daten, array $filter, array $parameter, int $budget): string
    {
        foreach ($filter as $i => $name) {
            $daten = match ($name) {
                'FlateDecode', 'Fl' => self::praediktor(self::inflate($daten, $budget), $parameter[$i] ?? []),
                'ASCIIHexDecode', 'AHx' => self::asciiHex($daten),
                'ASCII85Decode', 'A85' => self::ascii85($daten),
                default => throw new PdfDefekt('Filter nicht unterstützt.'),
            };
            if (strlen($daten) > $budget) {
                throw new PdfZuGross('Stream zu groß.');
            }
        }

        return $daten;
    }

    /**
     * Inflates a zlib stream piece by piece, so the limit holds before the
     * memory is spent - and tolerates what real files get wrong: a missing
     * or broken checksum at the end keeps what was inflated up to there.
     */
    private static function inflate(string $daten, int $budget): string
    {
        $kontext = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($kontext === false) {
            throw new PdfDefekt('zlib nicht verfügbar.');
        }

        $ergebnis = '';
        $laenge = strlen($daten);
        for ($pos = 0; $pos < $laenge; $pos += self::INFLATE_STUECK) {
            $stueck = self::ohneWarnung(static fn (): string|false => inflate_add($kontext, substr($daten, $pos, self::INFLATE_STUECK), ZLIB_SYNC_FLUSH));
            if ($stueck === false) {
                if ($ergebnis === '') {
                    throw new PdfDefekt('Stream nicht dekomprimierbar.');
                }
                break;
            }
            $ergebnis .= $stueck;
            if (strlen($ergebnis) > $budget) {
                throw new PdfZuGross('Stream zu groß.');
            }
            if (inflate_get_status($kontext) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $ergebnis;
    }

    /**
     * Runs a zlib call that reports broken input as an E_WARNING: the
     * warning is the expected outcome for a damaged stream here, not a
     * problem of the application, and must neither reach the error log nor
     * fail the test suite (`failOnWarning`).
     *
     * @param \Closure(): (string|false) $aufruf
     */
    private static function ohneWarnung(\Closure $aufruf): string|false
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return $aufruf();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private static function praediktor(string $daten, array $parameter): string
    {
        $praediktor = is_int($parameter['Predictor'] ?? null) ? $parameter['Predictor'] : 1;
        if ($praediktor < 2) {
            return $daten;
        }
        $farben = is_int($parameter['Colors'] ?? null) ? max(1, $parameter['Colors']) : 1;
        $bits = is_int($parameter['BitsPerComponent'] ?? null) ? max(1, $parameter['BitsPerComponent']) : 8;
        $spalten = is_int($parameter['Columns'] ?? null) ? max(1, $parameter['Columns']) : 1;
        $pixel = max(1, intdiv($farben * $bits + 7, 8));
        $zeile = intdiv($farben * $bits * $spalten + 7, 8);

        if ($praediktor === 2) {
            return self::tiff($daten, $zeile, $pixel);
        }

        return self::png($daten, $zeile, $pixel);
    }

    private static function tiff(string $daten, int $zeile, int $pixel): string
    {
        $ergebnis = '';
        foreach (str_split($daten, $zeile) as $roh) {
            $bytes = array_values(unpack('C*', $roh) ?: []);
            for ($i = $pixel, $n = count($bytes); $i < $n; $i++) {
                $bytes[$i] = ($bytes[$i] + $bytes[$i - $pixel]) & 0xFF;
            }
            $ergebnis .= pack('C*', ...$bytes);
        }

        return $ergebnis;
    }

    /** PNG predictors (RFC 2083 section 6): each row starts with its filter type byte. */
    private static function png(string $daten, int $zeile, int $pixel): string
    {
        $ergebnis = '';
        $vorher = array_fill(0, $zeile, 0);
        foreach (str_split($daten, $zeile + 1) as $roh) {
            $typ = ord($roh[0]);
            $bytes = array_values(unpack('C*', substr($roh, 1)) ?: []);
            $bytes = array_pad($bytes, $zeile, 0);
            for ($i = 0; $i < $zeile; $i++) {
                $links = $i >= $pixel ? $bytes[$i - $pixel] : 0;
                $oben = $vorher[$i];
                $obenLinks = $i >= $pixel ? $vorher[$i - $pixel] : 0;
                $bytes[$i] = match ($typ) {
                    1 => $bytes[$i] + $links,
                    2 => $bytes[$i] + $oben,
                    3 => $bytes[$i] + intdiv($links + $oben, 2),
                    4 => $bytes[$i] + self::paeth($links, $oben, $obenLinks),
                    default => $bytes[$i],
                } & 0xFF;
            }
            $ergebnis .= pack('C*', ...$bytes);
            $vorher = $bytes;
        }

        return $ergebnis;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return match (true) {
            $pa <= $pb && $pa <= $pc => $a,
            $pb <= $pc => $b,
            default => $c,
        };
    }

    private static function asciiHex(string $daten): string
    {
        $ende = strpos($daten, '>');
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $ende === false ? $daten : substr($daten, 0, $ende));
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }

    private static function ascii85(string $daten): string
    {
        $ende = strpos($daten, '~>');
        $daten = (string) preg_replace('/[\x00\t\n\x0C\r ]/', '', $ende === false ? $daten : substr($daten, 0, $ende));
        if (str_starts_with($daten, '<~')) {
            $daten = substr($daten, 2);
        }

        $ergebnis = '';
        $gruppe = [];
        $laenge = strlen($daten);
        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $daten[$i];
            if ($zeichen === 'z' && $gruppe === []) {
                $ergebnis .= "\0\0\0\0";
                continue;
            }
            $wert = ord($zeichen) - 33;
            if ($wert < 0 || $wert > 84) {
                throw new PdfDefekt('Ungültiges ASCII85.');
            }
            $gruppe[] = $wert;
            if (count($gruppe) === 5) {
                $ergebnis .= self::ascii85Gruppe($gruppe, 4);
                $gruppe = [];
            }
        }
        if ($gruppe !== []) {
            $fehlend = 5 - count($gruppe);
            $ergebnis .= self::ascii85Gruppe(array_pad($gruppe, 5, 84), 4 - $fehlend);
        }

        return $ergebnis;
    }

    /**
     * @param list<int> $gruppe five base-85 digits
     */
    private static function ascii85Gruppe(array $gruppe, int $bytes): string
    {
        $zahl = 0;
        foreach ($gruppe as $ziffer) {
            $zahl = $zahl * 85 + $ziffer;
        }

        return substr(pack('N', $zahl & 0xFFFFFFFF), 0, $bytes);
    }
}
