<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * Builds one PDF from a page's worth of JPEGs (docs/spec/03-erfassung-und-ki.md
 * section 3): "ein PDF (reines PHP, FPDF o. ä., JPEG unverändert eingebettet,
 * eine Seite je Bild, A4 bzw. Bildformat)". Written by hand instead of a
 * library: embedding an already-compressed JPEG unchanged via `/DCTDecode`
 * is a handful of PDF objects, and a hand-written generator can stream
 * object by object straight into App\Service\Storage\BlobService::store()
 * without ever holding the whole PDF (CLAUDE.md section 1).
 *
 * Each JPEG's bytes go into the file exactly as received - no re-encoding,
 * no re-compression. Rotation from the camera (EXIF orientation) and a
 * mismatched aspect ratio are handled entirely through the page's
 * transformation matrix, never by touching the image data.
 */
final class PdfAusBildern
{
    private const string TITEL_MUSTER = '/^R-\d{4}-\d{4,}$/';

    private const float MM_JE_PUNKT = 72 / 25.4;
    private const float A4_BREITE = 210 * self::MM_JE_PUNKT;
    private const float A4_HOEHE = 297 * self::MM_JE_PUNKT;

    /** Wie nah ein Seitenverhältnis an A4 liegen darf, um als A4 zu gelten. */
    private const float A4_TOLERANZ = 0.03;

    /**
     * @param iterable<string> $jpegs raw JPEG bytes, one entry per page, in
     *        page order - the only thing this class ever reads of a page
     * @param ?string $titel the submission's reference number ("R-2026-0147"),
     *        or null when there is none yet; anything else is refused rather
     *        than landing verbatim in a PDF string
     *
     * @return \Generator<string> PDF bytes, piece by piece
     */
    public static function erzeuge(iterable $jpegs, ?string $titel = null): \Generator
    {
        if ($titel !== null && preg_match(self::TITEL_MUSTER, $titel) !== 1) {
            throw new ProcessingException('Ungültiger PDF-Titel.');
        }
        if ((is_array($jpegs) || $jpegs instanceof \Countable) && count($jpegs) === 0) {
            throw new ProcessingException('Keine Seiten für das PDF.');
        }

        $pos = 0;
        $offsets = [];
        $emit = static function (int $objId, string $chunk) use (&$pos, &$offsets): string {
            $offsets[$objId] = $pos;
            $pos += strlen($chunk);

            return $chunk;
        };

        $header = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos += strlen($header);
        yield $header;

        $seiten = 0;
        $kids = [];
        $naechsteId = 4;

        foreach ($jpegs as $jpeg) {
            $info = JpegInfo::aus($jpeg);
            $imageId = $naechsteId++;
            $contentId = $naechsteId++;
            $pageId = $naechsteId++;
            $kids[] = $pageId;

            [$seitenbreite, $seitenhoehe] = self::seitenformat($info);
            $matrix = self::matrix($info->orientierung, $seitenbreite, $seitenhoehe);

            $farbraum = match ($info->components) {
                1 => '/DeviceGray',
                3 => '/DeviceRGB',
                4 => '/DeviceCMYK',
                default => throw new ProcessingException('Unbekannter JPEG-Farbraum.'),
            };
            $decode = $info->cmykInvertiert ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $imgDict = sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8%s /Filter /DCTDecode /Length %d >>',
                $info->width,
                $info->height,
                $farbraum,
                $decode,
                strlen($jpeg),
            );
            yield $emit($imageId, sprintf("%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\n", $imageId, $imgDict, $jpeg));

            $content = sprintf('q %s cm /Im0 Do Q', implode(' ', array_map(self::zahl(...), $matrix)));
            yield $emit($contentId, sprintf(
                "%d 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
                $contentId,
                strlen($content),
                $content,
            ));

            $pageObj = sprintf(
                "%d 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>\nendobj\n",
                $pageId,
                self::zahl($seitenbreite),
                self::zahl($seitenhoehe),
                $imageId,
                $contentId,
            );
            yield $emit($pageId, $pageObj);

            $seiten++;
        }

        if ($seiten === 0) {
            throw new ProcessingException('Keine Seiten für das PDF.');
        }

        $kidsListe = implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $kids));
        yield $emit(2, sprintf("2 0 obj\n<< /Type /Pages /Kids [%s] /Count %d >>\nendobj\n", $kidsListe, $seiten));
        yield $emit(1, "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

        $infoTeile = ['/Producer ' . self::pdfString('Vereinsbelege')];
        if ($titel !== null) {
            $infoTeile[] = '/Title ' . self::pdfString($titel);
        }
        yield $emit(3, sprintf("3 0 obj\n<< %s >>\nendobj\n", implode(' ', $infoTeile)));

        $maxId = 3 + $seiten * 3;
        $xrefStart = $pos;
        $xref = sprintf("xref\n0 %d\n0000000000 65535 f \n", $maxId + 1);
        for ($id = 1; $id <= $maxId; $id++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        yield $xref;

        yield sprintf("trailer\n<< /Size %d /Root 1 0 R /Info 3 0 R >>\nstartxref\n%d\n%%%%EOF", $maxId + 1, $xrefStart);
    }

    /**
     * A4 when the image is close enough to it (in either orientation), else
     * A4's short edge (portrait) or long edge (landscape) as the fixed width,
     * with the height following the image - a long till receipt stays one
     * tall page instead of being cropped or shrunk to fit A4.
     *
     * @return array{0: float, 1: float} width, height in points
     */
    private static function seitenformat(JpegInfo $info): array
    {
        $vertauscht = in_array($info->orientierung, [5, 6, 7, 8], true);
        $breitePx = $vertauscht ? $info->height : $info->width;
        $hoehePx = $vertauscht ? $info->width : $info->height;

        if ($breitePx < 1 || $hoehePx < 1) {
            throw new ProcessingException('JPEG ohne gültige Bildgröße.');
        }

        $verhaeltnis = $breitePx / $hoehePx;
        $a4Hochkant = self::A4_BREITE / self::A4_HOEHE;
        $a4Quer = self::A4_HOEHE / self::A4_BREITE;

        if (abs($verhaeltnis - $a4Hochkant) / $a4Hochkant <= self::A4_TOLERANZ) {
            return [self::A4_BREITE, self::A4_HOEHE];
        }
        if (abs($verhaeltnis - $a4Quer) / $a4Quer <= self::A4_TOLERANZ) {
            return [self::A4_HOEHE, self::A4_BREITE];
        }

        $seitenbreite = $verhaeltnis <= 1.0 ? self::A4_BREITE : self::A4_HOEHE;

        return [$seitenbreite, $seitenbreite / $verhaeltnis];
    }

    /**
     * The `cm` operands that map the image's unit square onto a `$breite` x
     * `$hoehe` page, applying the EXIF orientation (docs/spec/03-erfassung-
     * und-ki.md section 2: "die EXIF-Drehung per Matrix"): the JPEG bytes
     * embedded in the page are never touched, only how they are placed.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function matrix(int $orientierung, float $breite, float $hoehe): array
    {
        $w = $breite;
        $h = $hoehe;

        return match ($orientierung) {
            2 => [-$w, 0.0, 0.0, $h, $w, 0.0],
            3 => [-$w, 0.0, 0.0, -$h, $w, $h],
            4 => [$w, 0.0, 0.0, -$h, 0.0, $h],
            5 => [0.0, -$h, -$w, 0.0, $w, $h],
            6 => [0.0, -$h, $w, 0.0, 0.0, $h],
            7 => [0.0, $h, $w, 0.0, 0.0, 0.0],
            8 => [0.0, $h, -$w, 0.0, $w, 0.0],
            default => [$w, 0.0, 0.0, $h, 0.0, 0.0],
        };
    }

    private static function zahl(float $wert): string
    {
        $gerundet = round($wert, 3);
        if ($gerundet === (float) (int) $gerundet) {
            return (string) (int) $gerundet;
        }

        return rtrim(rtrim(sprintf('%.3F', $gerundet), '0'), '.');
    }

    private static function pdfString(string $text): string
    {
        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ')';
    }
}
