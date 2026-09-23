<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * Turns a PNG page into a JPEG so App\Service\Processing\PdfAusBildern only
 * ever has to embed one image format (docs/spec/03-erfassung-und-ki.md
 * section 3: "der Server nutzt nur GD als Fallback", CLAUDE.md section 1).
 *
 * Transparency is flattened onto white - the same choice the scanner's own
 * "Original (Farbe)" output makes, and a PDF page has no transparency of its
 * own to fall back on anyway.
 */
final class PngZuJpeg
{
    /** Guards against a decompression bomb: a tiny PNG that unpacks huge. */
    public const int MAX_PIXEL = 30_000_000;

    public const int QUALITAET = 90;

    public static function konvertiere(string $png): string
    {
        $groesse = @getimagesizefromstring($png);
        if ($groesse === false || ($groesse[2] ?? null) !== IMAGETYPE_PNG) {
            throw new ProcessingException('Keine PNG-Datei.');
        }

        if ($groesse[0] * $groesse[1] > self::MAX_PIXEL) {
            throw new ProcessingException('PNG ist zu groß.');
        }

        $original = @imagecreatefromstring($png);
        if ($original === false) {
            throw new ProcessingException('PNG konnte nicht gelesen werden.');
        }

        $breite = imagesx($original);
        $hoehe = imagesy($original);

        $weiss = imagecreatetruecolor($breite, $hoehe);
        imagefill($weiss, 0, 0, imagecolorallocate($weiss, 255, 255, 255));
        imagealphablending($weiss, true);
        imagecopy($weiss, $original, 0, 0, 0, 0, $breite, $hoehe);

        ob_start();
        imagejpeg($weiss, quality: self::QUALITAET);
        $jpeg = ob_get_clean();

        if (!is_string($jpeg) || $jpeg === '') {
            throw new ProcessingException('JPEG-Umwandlung fehlgeschlagen.');
        }

        return $jpeg;
    }
}
