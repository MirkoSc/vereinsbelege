<?php

declare(strict_types=1);

namespace App\Worker\Schritt;

use App\Service\Processing\JpegInfo;
use App\Service\Processing\ProcessingException;
use App\Service\Upload\MagicBytes;
use App\Worker\JobLauf;
use App\Worker\WerkzeugFehler;
use App\Worker\Werkzeuge;

/**
 * Shared by the steps: the originals of a job's document with their type
 * (from the magic bytes - the type in the blob's metadata is sealed to the
 * vault), and PDF pages as JPEG within the limits of the browser route
 * (App\Service\Document\PdfRasterung, public/js/rasterung.js): 150 DPI,
 * longest side at most 4000 px, at most 2 MiB, quality 85 → 70 → 50.
 */
final readonly class Quellen
{
    public const int MAX_SEITEN = 200;

    public const int MAX_BYTES = 2 * 1024 * 1024;

    public const int MAX_PIXEL = 4000;

    public const int DPI = 150;

    public const array QUALITAETEN = [85, 70, 50];

    private function __construct()
    {
    }

    public static function typ(string $inhalt): ?string
    {
        return MagicBytes::detect(substr($inhalt, 0, MagicBytes::HEAD_BYTES));
    }

    /**
     * The originals in page order, each as [blob id, plaintext, type].
     *
     * @return list<array{int, string, ?string}>
     */
    public static function originale(JobLauf $lauf): array
    {
        $liste = [];
        foreach ($lauf->kontext()->originale as $blobId) {
            $inhalt = $lauf->blob($blobId);
            $liste[] = [$blobId, $inhalt, self::typ($inhalt)];
        }

        return $liste;
    }

    /**
     * Page $seite of the PDF at $pdfDatei as JPEG.
     *
     * @throws WerkzeugFehler
     */
    public static function seitenbild(Werkzeuge $werkzeuge, string $pdfDatei, int $seite, string $verzeichnis, \Closure $puls): string
    {
        $ziel = $verzeichnis . '/seite.jpg';
        $laengste = null;
        foreach (self::QUALITAETEN as $qualitaet) {
            $werkzeuge->seiteRendern($pdfDatei, $seite, self::DPI, $laengste, $qualitaet, $ziel, $puls);
            $jpeg = (string) file_get_contents($ziel);
            @unlink($ziel);
            try {
                $info = JpegInfo::aus($jpeg);
            } catch (ProcessingException) {
                throw new WerkzeugFehler('PdfRasterungFehlgeschlagen', true);
            }
            if ($info->width > self::MAX_PIXEL || $info->height > self::MAX_PIXEL) {
                // A large page format: scaled down to the limit, same quality.
                $laengste = self::MAX_PIXEL;
                $werkzeuge->seiteRendern($pdfDatei, $seite, self::DPI, $laengste, $qualitaet, $ziel, $puls);
                $jpeg = (string) file_get_contents($ziel);
                @unlink($ziel);
            }
            if (strlen($jpeg) <= self::MAX_BYTES && self::typ($jpeg) === MagicBytes::JPEG) {
                return $jpeg;
            }
        }

        throw new WerkzeugFehler('SeiteZuGross', true);
    }

    /** Writes $inhalt to a new file in $verzeichnis (tmpfs) and returns its path. */
    public static function datei(string $verzeichnis, string $name, string $inhalt): string
    {
        $pfad = $verzeichnis . '/' . $name;
        if (file_put_contents($pfad, $inhalt) !== strlen($inhalt)) {
            throw new \RuntimeException('Arbeitsdatei kann nicht geschrieben werden.');
        }
        chmod($pfad, 0o600);

        return $pfad;
    }
}
