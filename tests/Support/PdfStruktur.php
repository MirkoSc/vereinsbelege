<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A small, purpose-built reader for the PDFs App\Service\Processing\
 * PdfAusBildern writes (issue #26/M4-4) - not a general PDF parser, just
 * enough structure checking to make the Pflicht-Test "gültiges PDF" mean
 * something: the xref table actually points at real objects, the page count
 * matches the number of images, and each page's embedded stream is the exact
 * JPEG bytes that went in.
 */
final readonly class PdfStruktur
{
    /**
     * @param list<array{breite: float, hoehe: float, jpeg: string}> $seiten
     */
    private function __construct(
        public array $seiten,
        public ?string $titel,
        public string $producer,
    ) {
    }

    public static function analysiere(string $pdf): self
    {
        if (!str_starts_with($pdf, '%PDF-1.4')) {
            throw new \RuntimeException('Kein PDF-1.4-Header.');
        }
        if (!str_ends_with($pdf, '%%EOF')) {
            throw new \RuntimeException('Kein %%EOF am Ende.');
        }

        if (preg_match('/startxref\s+(\d+)\s+%%EOF$/', $pdf, $treffer) !== 1) {
            throw new \RuntimeException('Kein startxref gefunden.');
        }
        $xrefStart = (int) $treffer[1];

        if (preg_match('/\Gxref\r?\n0 (\d+)\r?\n/', $pdf, $kopf, 0, $xrefStart) !== 1) {
            throw new \RuntimeException('xref-Tabelle nicht an der erwarteten Stelle.');
        }
        $anzahl = (int) $kopf[1];
        $einträgeStart = $xrefStart + strlen($kopf[0]);

        $offsets = [];
        for ($id = 0; $id < $anzahl; $id++) {
            $zeile = substr($pdf, $einträgeStart + $id * 20, 20);
            if (preg_match('/^(\d{10}) \d{5} [nf] \n?\r?$/', $zeile, $eintrag) !== 1) {
                throw new \RuntimeException(sprintf('xref-Eintrag %d ist nicht 20 Byte lang.', $id));
            }
            if ($id > 0) {
                $offsets[$id] = (int) $eintrag[1];
                self::pruefeObjektKopf($pdf, $id, $offsets[$id]);
            }
        }

        if (preg_match('/trailer\s*<<(.*?)>>/s', $pdf, $trailer) !== 1) {
            throw new \RuntimeException('Kein Trailer gefunden.');
        }
        if (preg_match('/\/Root\s+(\d+)\s+0\s+R/', $trailer[1], $root) !== 1) {
            throw new \RuntimeException('Trailer ohne /Root.');
        }
        if (preg_match('/\/Size\s+(\d+)/', $trailer[1], $size) !== 1 || (int) $size[1] !== $anzahl) {
            throw new \RuntimeException('Trailer-/Size passt nicht zur xref-Tabelle.');
        }

        $catalog = self::objektKoerper($pdf, (int) $root[1], $offsets);
        if (preg_match('/\/Pages\s+(\d+)\s+0\s+R/', $catalog, $pagesRef) !== 1) {
            throw new \RuntimeException('Catalog ohne /Pages.');
        }

        $pages = self::objektKoerper($pdf, (int) $pagesRef[1], $offsets);
        if (preg_match('/\/Kids\s*\[(.*?)\]/', $pages, $kidsMatch) !== 1) {
            throw new \RuntimeException('Pages ohne /Kids.');
        }
        if (preg_match('/\/Count\s+(\d+)/', $pages, $countMatch) !== 1) {
            throw new \RuntimeException('Pages ohne /Count.');
        }
        preg_match_all('/(\d+)\s+0\s+R/', $kidsMatch[1], $kidsTreffer);
        $kids = array_map(intval(...), $kidsTreffer[1]);

        if (count($kids) !== (int) $countMatch[1]) {
            throw new \RuntimeException('/Count passt nicht zur Anzahl der /Kids.');
        }

        $seiten = [];
        foreach ($kids as $pageId) {
            $seiten[] = self::seite($pdf, $pageId, $offsets);
        }

        $info = isset($trailer[1]) && preg_match('/\/Info\s+(\d+)\s+0\s+R/', $trailer[1], $infoRef) === 1
            ? self::objektKoerper($pdf, (int) $infoRef[1], $offsets)
            : null;

        $titel = $info !== null && preg_match('/\/Title\s*\(([^)]*)\)/', $info, $titelMatch) === 1
            ? $titelMatch[1]
            : null;
        if ($info === null || preg_match('/\/Producer\s*\(([^)]*)\)/', $info, $producerMatch) !== 1) {
            throw new \RuntimeException('Info-Dictionary ohne /Producer.');
        }

        return new self($seiten, $titel, $producerMatch[1]);
    }

    /**
     * @param array<int, int> $offsets
     * @return array{breite: float, hoehe: float, jpeg: string}
     */
    private static function seite(string $pdf, int $pageId, array $offsets): array
    {
        $page = self::objektKoerper($pdf, $pageId, $offsets);

        if (preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $page, $box) !== 1) {
            throw new \RuntimeException('Page ohne /MediaBox.');
        }
        if (preg_match('/\/Im0\s+(\d+)\s+0\s+R/', $page, $imgRef) !== 1) {
            throw new \RuntimeException('Page ohne Bild-XObject.');
        }
        if (preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $page, $contentRef) !== 1) {
            throw new \RuntimeException('Page ohne /Contents.');
        }

        $content = self::objektKoerper($pdf, (int) $contentRef[1], $offsets);
        if (!str_contains($content, '/Im0 Do')) {
            throw new \RuntimeException('Content-Stream zeichnet das Bild nicht.');
        }

        $imageOffset = $offsets[(int) $imgRef[1]] ?? throw new \RuntimeException('Bild-Objekt fehlt in der xref-Tabelle.');
        if (preg_match('/^(\d+) 0 obj\n(<<.*?>>)\nstream\n/s', substr($pdf, $imageOffset), $imgKopf) !== 1) {
            throw new \RuntimeException('Bild-Objekt ohne Stream.');
        }
        if (preg_match('/\/Length\s+(\d+)/', $imgKopf[2], $laenge) !== 1) {
            throw new \RuntimeException('Bild-Objekt ohne /Length.');
        }

        $streamStart = $imageOffset + strlen($imgKopf[0]);
        $jpeg = substr($pdf, $streamStart, (int) $laenge[1]);
        if (substr($pdf, $streamStart + strlen($jpeg), 10) !== "\nendstream") {
            throw new \RuntimeException('Bild-Stream-Länge stimmt nicht mit /Length überein.');
        }

        return ['breite' => (float) $box[1], 'hoehe' => (float) $box[2], 'jpeg' => $jpeg];
    }

    /**
     * @param array<int, int> $offsets
     */
    private static function objektKoerper(string $pdf, int $id, array $offsets): string
    {
        $offset = $offsets[$id] ?? throw new \RuntimeException(sprintf('Objekt %d fehlt in der xref-Tabelle.', $id));
        if (preg_match('/^\d+ 0 obj\n(.*?)\nendobj/s', substr($pdf, $offset), $treffer) !== 1) {
            throw new \RuntimeException(sprintf('Objekt %d ist nicht lesbar.', $id));
        }

        return $treffer[1];
    }

    /**
     * @param array<int, int> $offsets
     */
    private static function pruefeObjektKopf(string $pdf, int $id, int $offset): void
    {
        if (preg_match(sprintf('/^%d 0 obj\n/', $id), substr($pdf, $offset, 32)) !== 1) {
            throw new \RuntimeException(sprintf('xref-Offset für Objekt %d zeigt nicht auf "%d 0 obj".', $id, $id));
        }
    }
}
