<?php

declare(strict_types=1);

namespace App\Worker\Schritt;

use App\Domain\ArtifactKind;
use App\Service\Upload\MagicBytes;
use App\Worker\JobLauf;
use App\Worker\Werkzeuge;

/**
 * `render_pages` with Poppler (docs/spec/07-worker.md section 2): every
 * PDF original of the document becomes JPEG page images, `seq` counted
 * across all of them in page order - exactly what the browser route
 * stores (App\Service\Document\PdfRasterung). One `page_image` blob per
 * page; the web host takes the run over only when every page opens
 * (App\Service\Worker\WorkerUebernahme), else the browser renders.
 */
final readonly class SeitenRendern implements Schritt
{
    public function __construct(private Werkzeuge $werkzeuge)
    {
    }

    public function typ(): string
    {
        return 'render_pages';
    }

    public function ausfuehren(JobLauf $lauf, string $verzeichnis): void
    {
        $seq = 0;
        foreach ($lauf->kontext()->originale as $nummer => $blobId) {
            $inhalt = $lauf->blob($blobId);
            if (Quellen::typ($inhalt) !== MagicBytes::PDF) {
                continue;
            }
            $pdf = Quellen::datei($verzeichnis, 'quelle-' . $nummer . '.pdf', $inhalt);
            sodium_memzero($inhalt);
            $seiten = min($this->werkzeuge->seitenzahl($pdf, $lauf->pulsGeber()), Quellen::MAX_SEITEN - $seq);
            for ($seite = 1; $seite <= $seiten; ++$seite) {
                $jpeg = Quellen::seitenbild($this->werkzeuge, $pdf, $seite, $verzeichnis, $lauf->pulsGeber());
                $lauf->blobHochladen(ArtifactKind::PageImage, $seq++, $jpeg);
                sodium_memzero($jpeg);
            }
            unlink($pdf);
        }
    }
}
