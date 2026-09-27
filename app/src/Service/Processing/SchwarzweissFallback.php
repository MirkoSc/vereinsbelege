<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * The server fallback for a page that arrived without the browser's
 * processing (docs/spec/03-erfassung-und-ki.md section 2, issue #34/M5-4):
 * an old device or a browser without canvas/createImageBitmap uploads only
 * the original photo. GD then does what the spec allows the server to do -
 * "graustufen + globale Schwelle; kein Entzerren" (CLAUDE.md section 1: GD is
 * the only image tool there is on the host).
 *
 * The threshold is global, but not a fixed 128: it is Otsu's, taken from the
 * page's own histogram, so a dim photo does not come out all black. No
 * adaptive threshold like the browser's Bradley (public/js/scanner/
 * schwelle.js) - that needs an integral image the size of the page, which is
 * the work the spec deliberately leaves to the browser.
 *
 * Before thresholding the page is turned upright by its EXIF orientation
 * (App\Service\Processing\JpegInfo) - GD drops EXIF when it re-encodes, so
 * App\Service\Processing\PdfAusBildern could no longer apply it - and scaled
 * down to the browser's own output size (at most 2480 px on the long edge,
 * ≈ 300 dpi A4), which also keeps the per-pixel loop of one page well inside
 * one short request (CLAUDE.md section 1).
 *
 * Pure: bytes in, JPEG bytes out, no Http/Session/Repository (CLAUDE.md
 * section 6a). The original is never touched - the caller stores the result
 * as a blob of its own (decision E-10).
 */
final class SchwarzweissFallback
{
    /** The same ceiling as PngZuJpeg, checked before a pixel is decoded. */
    public const int MAX_PIXEL = PngZuJpeg::MAX_PIXEL;

    /** Long edge of the result - the browser scanner's `maxKante`. */
    public const int MAX_KANTE = 2480;

    /** JPEG quality of the result - the browser scanner's 0.85. */
    public const int QUALITAET = 85;

    public static function aufbereiten(string $bild): string
    {
        $groesse = @getimagesizefromstring($bild);
        $typ = $groesse === false ? null : ($groesse[2] ?? null);
        if ($typ !== IMAGETYPE_JPEG && $typ !== IMAGETYPE_PNG) {
            throw new ProcessingException('Kein JPEG- oder PNG-Bild.');
        }

        if ($groesse[0] * $groesse[1] > self::MAX_PIXEL) {
            throw new ProcessingException('Bild ist zu groß.');
        }

        // A PNG is flattened onto white first, exactly as the PDF path does
        // for an unprocessed PNG (App\Service\Processing\PngZuJpeg); a PNG has
        // no EXIF orientation.
        $orientierung = 1;
        if ($typ === IMAGETYPE_PNG) {
            $bild = PngZuJpeg::konvertiere($bild);
        } else {
            $orientierung = JpegInfo::aus($bild)->orientierung;
        }

        $gd = @imagecreatefromstring($bild);
        if ($gd === false) {
            throw new ProcessingException('Bild konnte nicht gelesen werden.');
        }

        $gd = self::verkleinern($gd);
        $gd = self::aufrichten($gd, $orientierung);

        imagefilter($gd, IMG_FILTER_GRAYSCALE);
        $schwelle = self::otsu(self::histogramm($gd));
        self::schwelleAnwenden($gd, $schwelle);

        ob_start();
        imagejpeg($gd, quality: self::QUALITAET);
        $jpeg = ob_get_clean();

        if (!is_string($jpeg) || $jpeg === '') {
            throw new ProcessingException('JPEG-Umwandlung fehlgeschlagen.');
        }

        return $jpeg;
    }

    /**
     * Otsu's threshold of a 256-bin histogram: the grey value that best
     * separates dark ink from light paper (maximum between-class variance).
     * Pixels below it become black, the rest white. A page of one single
     * value has nothing to separate and keeps everything white.
     *
     * @param list<int> $histogramm 256 counts
     */
    public static function otsu(array $histogramm): int
    {
        $gesamt = array_sum($histogramm);
        if ($gesamt === 0) {
            return 0;
        }

        $summeAlle = 0;
        foreach ($histogramm as $wert => $anzahl) {
            $summeAlle += $wert * $anzahl;
        }

        $besteSchwelle = 0;
        $besteVarianz = -1.0;
        $gewichtDunkel = 0;
        $summeDunkel = 0;
        for ($t = 0; $t < 256; $t++) {
            // Class "dark" = values below $t, class "light" = $t and above.
            if ($gewichtDunkel > 0 && $gewichtDunkel < $gesamt) {
                $gewichtHell = $gesamt - $gewichtDunkel;
                $mittelDunkel = $summeDunkel / $gewichtDunkel;
                $mittelHell = ($summeAlle - $summeDunkel) / $gewichtHell;
                $varianz = $gewichtDunkel * $gewichtHell * ($mittelDunkel - $mittelHell) ** 2;
                if ($varianz > $besteVarianz) {
                    $besteVarianz = $varianz;
                    $besteSchwelle = $t;
                }
            }
            $gewichtDunkel += $histogramm[$t] ?? 0;
            $summeDunkel += $t * ($histogramm[$t] ?? 0);
        }

        return $besteSchwelle;
    }

    private static function verkleinern(\GdImage $gd): \GdImage
    {
        $breite = imagesx($gd);
        $hoehe = imagesy($gd);
        $lang = max($breite, $hoehe);
        if ($lang <= self::MAX_KANTE) {
            return $gd;
        }

        $faktor = self::MAX_KANTE / $lang;
        $klein = imagescale(
            $gd,
            max(1, (int) round($breite * $faktor)),
            max(1, (int) round($hoehe * $faktor)),
            IMG_BILINEAR_FIXED,
        );
        if ($klein === false) {
            throw new ProcessingException('Bild konnte nicht verkleinert werden.');
        }

        return $klein;
    }

    /**
     * Applies an EXIF orientation (1..8) so the pixels themselves stand
     * upright. imagerotate() turns counter-clockwise for a positive angle.
     */
    private static function aufrichten(\GdImage $gd, int $orientierung): \GdImage
    {
        if (in_array($orientierung, [2, 5, 7], true)) {
            imageflip($gd, IMG_FLIP_HORIZONTAL);
        }
        if ($orientierung === 4) {
            imageflip($gd, IMG_FLIP_VERTICAL);
        }

        $winkel = match ($orientierung) {
            3 => 180,
            5, 8 => 90,  // counter-clockwise
            6, 7 => 270, // clockwise
            default => 0,
        };
        if ($winkel === 0) {
            return $gd;
        }

        $gedreht = imagerotate($gd, $winkel, 0);
        if ($gedreht === false) {
            throw new ProcessingException('Bild konnte nicht gedreht werden.');
        }

        return $gedreht;
    }

    /**
     * Histogram of a greyscale image; after IMG_FILTER_GRAYSCALE red, green
     * and blue are equal, so the blue byte is the grey value.
     *
     * @return list<int>
     */
    private static function histogramm(\GdImage $gd): array
    {
        $histogramm = array_fill(0, 256, 0);
        $breite = imagesx($gd);
        $hoehe = imagesy($gd);
        for ($y = 0; $y < $hoehe; $y++) {
            for ($x = 0; $x < $breite; $x++) {
                $histogramm[imagecolorat($gd, $x, $y) & 0xFF]++;
            }
        }

        return $histogramm;
    }

    private static function schwelleAnwenden(\GdImage $gd, int $schwelle): void
    {
        $breite = imagesx($gd);
        $hoehe = imagesy($gd);
        for ($y = 0; $y < $hoehe; $y++) {
            for ($x = 0; $x < $breite; $x++) {
                imagesetpixel($gd, $x, $y, (imagecolorat($gd, $x, $y) & 0xFF) < $schwelle ? 0x000000 : 0xFFFFFF);
            }
        }
    }
}
