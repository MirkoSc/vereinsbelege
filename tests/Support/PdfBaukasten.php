<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Builds small PDFs object by object for the text layer tests
 * (App\Service\Processing\Pdf\PdfTextlayer, issue #45/M7-3): exactly the
 * structure a test is about - a font without ToUnicode, a Type0 font, an
 * object stream, an incremental update - and nothing else. Writes a correct
 * xref table (or an XRef stream when objects are packed into an object
 * stream), even though the reader under test does not rely on it.
 */
final class PdfBaukasten
{
    /** @var array<int, string> object number → body (everything between "obj" and "endobj") */
    private array $objekte = [];

    /** @var list<int> objects that go into an object stream instead of the file body */
    private array $gepackt = [];

    private int $naechste = 1;

    public function reservieren(): int
    {
        return $this->naechste++;
    }

    public function objekt(string $inhalt, ?int $nummer = null): int
    {
        $nummer ??= $this->reservieren();
        $this->objekte[$nummer] = $inhalt;

        return $nummer;
    }

    /**
     * A stream object; `$dict` without the `<< >>` and without /Length.
     */
    public function stream(string $daten, string $dict = '', bool $komprimiert = false, ?int $nummer = null): int
    {
        if ($komprimiert) {
            $daten = (string) gzcompress($daten);
            $dict .= ' /Filter /FlateDecode';
        }

        return $this->objekt(sprintf("<< %s /Length %d >>\nstream\n%s\nendstream", trim($dict), strlen($daten), $daten), $nummer);
    }

    /** Packs this (non-stream) object into the object stream pdf() writes. */
    public function packen(int $nummer): void
    {
        $this->gepackt[] = $nummer;
    }

    public function pdf(int $katalog, string $trailerZusatz = ''): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        if ($this->gepackt !== []) {
            $kopf = '';
            $koerper = '';
            foreach ($this->gepackt as $nummer) {
                $kopf .= $nummer . ' ' . strlen($koerper) . ' ';
                $koerper .= $this->objekte[$nummer] . "\n";
            }
            $objektStream = $this->reservieren();
            $offsets[$objektStream] = strlen($pdf);
            $pdf .= $objektStream . " 0 obj\n" . $this->streamKoerper($kopf . $koerper, sprintf('/Type /ObjStm /N %d /First %d', count($this->gepackt), strlen($kopf))) . "\nendobj\n";
        }

        foreach ($this->objekte as $nummer => $inhalt) {
            if (in_array($nummer, $this->gepackt, true)) {
                continue;
            }
            $offsets[$nummer] = strlen($pdf);
            $pdf .= $nummer . " 0 obj\n" . $inhalt . "\nendobj\n";
        }

        $groesse = max(array_keys($offsets + [0 => 0])) + 2;
        if ($this->gepackt !== []) {
            // An XRef stream as trailer (PDF 1.5) - its rows are not what
            // the reader uses, but its dictionary is the trailer.
            $xrefNummer = $groesse - 1;
            $pdf .= sprintf(
                "%d 0 obj\n%s\nendobj\nstartxref\n%d\n%%%%EOF\n",
                $xrefNummer,
                $this->streamKoerper('', sprintf('/Type /XRef /Size %d /W [1 2 1] /Root %d 0 R %s', $groesse, $katalog, $trailerZusatz)),
                strlen($pdf),
            );

            return $pdf;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . $groesse . "\n0000000000 65535 f \n";
        for ($nummer = 1; $nummer < $groesse; $nummer++) {
            $pdf .= isset($offsets[$nummer]) ? sprintf("%010d 00000 n \n", $offsets[$nummer]) : "0000000000 65535 f \n";
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root %d 0 R %s >>\nstartxref\n%d\n%%%%EOF\n", $groesse, $katalog, $trailerZusatz, $xref);

        return $pdf;
    }

    /**
     * A one-page document: catalog, page tree, the page with font `/F1`
     * (and `/F2` if given) and the content stream.
     */
    public static function seite(
        string $inhalt,
        string $schrift = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        bool $komprimiert = false,
        ?string $schrift2 = null,
    ): string {
        $pdf = new self();
        $katalog = $pdf->reservieren();
        $seiten = $pdf->reservieren();
        $f1 = $pdf->objekt($schrift);
        $fonts = sprintf('/F1 %d 0 R', $f1);
        if ($schrift2 !== null) {
            $fonts .= sprintf(' /F2 %d 0 R', $pdf->objekt($schrift2));
        }
        $inhaltNr = $pdf->stream($inhalt, komprimiert: $komprimiert);
        $seite = $pdf->objekt(sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] /Resources << /Font << %s >> >> /Contents %d 0 R >>',
            $seiten,
            $fonts,
            $inhaltNr,
        ));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $seiten);
        $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $seiten), $katalog);

        return $pdf->pdf($katalog);
    }

    /** A literal PDF string of Windows-1252 bytes, from UTF-8. */
    public static function winAnsi(string $text): string
    {
        $bytes = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return '(' . addcslashes($bytes, '()\\') . ')';
    }

    private function streamKoerper(string $daten, string $dict): string
    {
        $daten = (string) gzcompress($daten);

        return sprintf("<< %s /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream", $dict, strlen($daten), $daten);
    }
}
