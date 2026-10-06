<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

use App\Service\Processing\Textlayer;
use App\Service\Processing\TextlayerBefund;

/**
 * Reads the text layer of a PDF (docs/spec/03-erfassung-und-ki.md section 3,
 * issue #45/M7-3) - in plain PHP, no external tool and no third-party
 * library (CLAUDE.md section 1; why not smalot/pdfparser: same section of
 * the spec). Framework-free like everything in App\Service\Processing
 * (CLAUDE.md section 6a), so the optional worker can run it unchanged.
 *
 * Never throws for what is in the file: a damaged, encrypted or oversized
 * PDF gives a Textlayer without text and says why
 * (App\Service\Processing\TextlayerBefund) - the document then takes the
 * page image route, which is exactly what a scanned PDF does anyway.
 *
 * The limits keep a single call within a shared-hosting request
 * (CLAUDE.md section 1): only the streams that carry text are decoded at
 * all (never an image), and together they may not expand to more than
 * MAX_DEKODIERT bytes.
 */
final class PdfTextlayer
{
    /** Pages read at most - the same bound as rendering (App\Service\Document\PdfRasterung). */
    public const int MAX_SEITEN = 200;

    /** Decoded bytes of content streams, fonts' maps and object streams together. */
    public const int MAX_DEKODIERT = 32 * 1024 * 1024;

    /** Text kept at most, in bytes of UTF-8 - far more than any invoice and than an AI prompt takes. */
    public const int MAX_TEXT = 512 * 1024;

    public static function lesen(string $pdf): Textlayer
    {
        try {
            $dokument = new PdfDokument($pdf, self::MAX_DEKODIERT);
            if ($dokument->verschluesselt()) {
                return new Textlayer(TextlayerBefund::Verschluesselt, []);
            }

            $seiten = $dokument->seiten(self::MAX_SEITEN);
            if ($seiten === []) {
                return new Textlayer(TextlayerBefund::Defekt, []);
            }

            $inhalt = new PdfInhalt($dokument, self::MAX_TEXT);
            $texte = [];
            $laenge = 0;
            foreach ($seiten as [$seite, $resourcen]) {
                $text = $laenge < self::MAX_TEXT ? self::bereinigen($inhalt->seite($seite, $resourcen)) : '';
                $laenge += strlen($text);
                $texte[] = $text;
            }

            return new Textlayer(TextlayerBefund::Gelesen, $texte);
        } catch (PdfZuGross) {
            return new Textlayer(TextlayerBefund::ZuGross, []);
        } catch (PdfDefekt) {
            return new Textlayer(TextlayerBefund::Defekt, []);
        } catch (\TypeError | \ValueError | \ArithmeticError) {
            // Malformed structures this reader did not anticipate - the
            // same outcome as a damaged file.
            return new Textlayer(TextlayerBefund::Defekt, []);
        }
    }

    /** Lines trimmed, runs of spaces folded, no empty lines, valid UTF-8 only. */
    private static function bereinigen(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $zeilen = array_filter(array_map(trim(...), explode("\n", $text)), static fn (string $z): bool => $z !== '');

        return implode("\n", $zeilen);
    }
}
