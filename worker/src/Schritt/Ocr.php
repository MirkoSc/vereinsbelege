<?php

declare(strict_types=1);

namespace App\Worker\Schritt;

use App\Domain\ArtifactKind;
use App\Service\Processing\Textlayer;
use App\Service\Processing\TextlayerBefund;
use App\Service\Upload\MagicBytes;
use App\Worker\JobFehler;
use App\Worker\JobLauf;
use App\Worker\Werkzeuge;

/**
 * `ocr` with OCRmyPDF (docs/spec/07-worker.md section 2: Tesseract
 * `deu`+`eng`, rotation and skew corrected, PDF/A-2b). Per original, in
 * order, `seq` = its place: a `text` result in the shape of the web host's
 * own text layer (`{befund, brauchbar, seiten}`, section 5: one per
 * original) and the `pdfa` it was read from. "Brauchbar" is decided by
 * App\Service\Processing\Textlayer - the same rule as for a PDF's own
 * text layer.
 *
 * An image page is read from its processed scan if the browser made one
 * (straightened, black and white), else from the photo itself. An original
 * OCR cannot read (a HEIC without processed scan, an XML e-invoice) or one
 * without any recognised text ends the job for good: the web host only
 * takes a complete run over, so it skips OCR for this receipt instead.
 */
final readonly class Ocr implements Schritt
{
    public function __construct(private Werkzeuge $werkzeuge)
    {
    }

    public function typ(): string
    {
        return 'ocr';
    }

    public function ausfuehren(JobLauf $lauf, string $verzeichnis): void
    {
        $kontext = $lauf->kontext();
        foreach ($kontext->originale as $nummer => $blobId) {
            $verarbeitet = $kontext->verarbeitet($nummer);
            $inhalt = $lauf->blob($verarbeitet ?? $blobId);
            $typ = Quellen::typ($inhalt);
            if ($verarbeitet !== null && $typ !== MagicBytes::JPEG) {
                sodium_memzero($inhalt);
                $inhalt = $lauf->blob($blobId);
                $typ = Quellen::typ($inhalt);
            }
            $endung = match ($typ) {
                MagicBytes::PDF => 'pdf',
                MagicBytes::JPEG => 'jpg',
                MagicBytes::PNG => 'png',
                default => throw new JobFehler('OcrNichtMoeglich', true),
            };

            $eingabe = Quellen::datei($verzeichnis, 'quelle-' . $nummer . '.' . $endung, $inhalt);
            sodium_memzero($inhalt);
            $pdfa = $verzeichnis . '/pdfa-' . $nummer . '.pdf';
            $textDatei = $verzeichnis . '/text-' . $nummer . '.txt';
            $this->werkzeuge->ocr($eingabe, $typ !== MagicBytes::PDF, $pdfa, $textDatei, $lauf->pulsGeber());
            unlink($eingabe);

            $seiten = self::seiten((string) file_get_contents($textDatei));
            unlink($textDatei);
            if (array_filter($seiten, static fn(string $s): bool => trim($s) !== '') === []) {
                throw new JobFehler('OcrOhneText', true);
            }
            $textlayer = new Textlayer(TextlayerBefund::Gelesen, $seiten);
            $json = json_encode(
                ['befund' => TextlayerBefund::Gelesen->value, 'brauchbar' => $textlayer->brauchbar(), 'seiten' => $seiten],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );

            $pdfaInhalt = (string) file_get_contents($pdfa);
            unlink($pdfa);
            $lauf->blobHochladen(ArtifactKind::Pdfa, $nummer, $pdfaInhalt);
            sodium_memzero($pdfaInhalt);
            $lauf->datenHochladen(ArtifactKind::Text, $nummer, $json);
            sodium_memzero($json);
        }
    }

    /**
     * The sidecar's pages: separated by a form feed, the last one usually
     * followed by one as well.
     *
     * @return list<string>
     */
    public static function seiten(string $text): array
    {
        $seiten = explode(Textlayer::SEITENWECHSEL, str_replace("\r\n", "\n", $text));
        if (count($seiten) > 1 && trim((string) end($seiten)) === '') {
            array_pop($seiten);
        }

        return array_values(array_map(static fn(string $s): string => (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8'), $seiten));
    }
}
