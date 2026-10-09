<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * ZUGFeRD/Factur-X-style PDFs for the e-invoice tests (issue #46/M7-4): one
 * page of visible text plus embedded files, the way the standards attach
 * the invoice XML - a file specification with `/EF /F`, listed in the
 * catalog's `/Names /EmbeddedFiles` name tree and, PDF/A-3 style, in its
 * `/AF` array. Built on PdfBaukasten; also wrote
 * tests/fixtures/erechnung/zugferd-en16931.pdf (see the README there).
 */
final class ERechnungPdfBaukasten
{
    /**
     * @param list<array{string, string}> $dateien [file name, contents], embedded Flate-compressed
     * @param bool $kinder split the name tree into a root with `/Kids`
     * @param bool $nurAf list the files only in `/AF`, no name tree
     * @param bool $nameUtf16 write the names as UTF-16BE `/UF` text strings
     */
    public static function pdf(
        array $dateien,
        string $text = 'Rechnung',
        bool $kinder = false,
        bool $nurAf = false,
        bool $nameUtf16 = false,
        string $trailerZusatz = '',
    ): string {
        $pdf = new PdfBaukasten();
        $katalog = $pdf->reservieren();
        $seiten = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $zeilen = array_map(static fn(string $zeile): string => PdfBaukasten::winAnsi($zeile) . ' Tj', explode("\n", $text));
        $inhalt = $pdf->stream('BT /F1 11 Tf 14 TL 72 800 Td ' . implode(" T*\n", $zeilen) . ' ET');
        $seite = $pdf->objekt(sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
            $seiten,
            $schrift,
            $inhalt,
        ));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $seiten);

        $eintraege = [];
        $specs = [];
        foreach ($dateien as [$name, $daten]) {
            $stream = $pdf->stream($daten, sprintf('/Type /EmbeddedFile /Subtype /text#2Fxml /Params << /Size %d >>', strlen($daten)), komprimiert: true);
            $nameString = $nameUtf16
                ? '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($name, 'UTF-16BE', 'UTF-8'))) . '>'
                : '(' . addcslashes($name, '()\\') . ')';
            $spec = $pdf->objekt(sprintf(
                '<< /Type /Filespec /F (datei.bin) /UF %s /AFRelationship /Alternative /EF << /F %d 0 R /UF %d 0 R >> >>',
                $nameString,
                $stream,
                $stream,
            ));
            $specs[] = $spec;
            $eintraege[] = sprintf('%s %d 0 R', $nameString, $spec);
        }

        $zusatz = sprintf(' /AF [%s]', implode(' ', array_map(static fn(int $spec): string => $spec . ' 0 R', $specs)));
        if (!$nurAf) {
            if ($kinder && count($eintraege) > 1) {
                $blaetter = array_map(
                    static fn(string $eintrag): string => $pdf->objekt(sprintf('<< /Names [%s] >>', $eintrag)) . ' 0 R',
                    $eintraege,
                );
                $baum = $pdf->objekt(sprintf('<< /Kids [%s] >>', implode(' ', $blaetter)));
            } else {
                $baum = $pdf->objekt(sprintf('<< /Names [%s] >>', implode(' ', $eintraege)));
            }
            $zusatz .= sprintf(' /Names << /EmbeddedFiles %d 0 R >>', $baum);
        }
        $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R%s >>', $seiten, $zusatz), $katalog);

        return $pdf->pdf($katalog, $trailerZusatz);
    }
}
