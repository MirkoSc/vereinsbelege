<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing\Pdf;

use App\Service\Processing\Pdf\PdfTextlayer;
use App\Service\Processing\PdfAusBildern;
use App\Service\Processing\Textlayer;
use App\Service\Processing\TextlayerBefund;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\PdfBaukasten;
use PHPUnit\Framework\TestCase;

/**
 * Text layer extraction of digital PDFs (issue #45/M7-3,
 * docs/spec/03-erfassung-und-ki.md section 3): plain PHP, the structures
 * real generators write (standard fonts, embedded subsets with ToUnicode,
 * composite fonts, object streams, Form XObjects, incremental updates), and
 * never an exception or a PHP warning for a file that is damaged, encrypted
 * or built to exhaust memory - those simply have no text, and the document
 * takes the page image route.
 */
final class PdfTextlayerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../fixtures/pdf';

    public function testAStandardFontInWinAnsiEncoding(): void
    {
        $pdf = PdfBaukasten::seite('BT /F1 12 Tf 72 800 Td ' . PdfBaukasten::winAnsi('Rechnung für Größe 5 – 12,50 €') . ' Tj ET');

        $textlayer = PdfTextlayer::lesen($pdf);

        self::assertSame(TextlayerBefund::Gelesen, $textlayer->befund);
        self::assertSame(['Rechnung für Größe 5 – 12,50 €'], $textlayer->seiten);
    }

    public function testANewBaselineIsANewLine(): void
    {
        $pdf = PdfBaukasten::seite(
            "BT /F1 12 Tf 14 TL 72 800 Td (Zeile eins) Tj 0 -14 Td (Zeile zwei) Tj T* (Zeile drei) Tj (Zeile vier) '"
            . ' 1 0 0 1 72 700 Tm (Zeile f\374nf) Tj ET',
        );

        self::assertSame("Zeile eins\nZeile zwei\nZeile drei\nZeile vier\nZeile fünf", PdfTextlayer::lesen($pdf)->text());
    }

    /**
     * A TJ adjustment of a few thousandths is kerning, a larger one is the
     * gap between words; so is a jump to the right on the same baseline.
     */
    public function testSpacesComeFromGapsNotFromKerning(): void
    {
        $pdf = PdfBaukasten::seite(
            'BT /F1 12 Tf 72 800 Td [(Rech) -20 (nung) -400 (Nr.)] TJ (4711) Tj ET'
            . ' BT /F1 12 Tf 72 780 Td (Gesamt) Tj 200 0 Td (119,00) Tj ( EUR) Tj ET',
        );

        self::assertSame("Rechnung Nr.4711\nGesamt 119,00 EUR", PdfTextlayer::lesen($pdf)->text());
    }

    public function testScaledTextMatrixAndCtm(): void
    {
        $pdf = PdfBaukasten::seite(
            'q 0.5 0 0 0.5 0 0 cm BT /F1 1 Tf 24 0 0 24 144 1400 Tm (Gro\337) Tj 24 0 0 24 144 1372 Tm (Klein) Tj'
            . ' 24 0 0 24 400 1372 Tm (rechts) Tj ET Q',
        );

        self::assertSame("Groß\nKlein rechts", PdfTextlayer::lesen($pdf)->text());
    }

    public function testCompressedAndChainedFilters(): void
    {
        $inhalt = 'BT /F1 12 Tf 72 800 Td (Komprimiert gelesen) Tj ET';

        $flate = PdfBaukasten::seite($inhalt, komprimiert: true);
        self::assertSame('Komprimiert gelesen', PdfTextlayer::lesen($flate)->text());

        $pdf = new PdfBaukasten();
        $hex = $pdf->stream(bin2hex($inhalt) . '>', '/Filter /ASCIIHexDecode');
        $kette = $pdf->stream(self::ascii85((string) gzcompress($inhalt)) . '~>', '/Filter [/ASCII85Decode /FlateDecode]');
        $praediktor = $pdf->stream((string) gzcompress(self::pngUp($inhalt, 8)), '/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 8 >>');
        $katalog = self::dreiSeiten($pdf, [$hex, $kette, $praediktor]);

        self::assertSame(
            ['Komprimiert gelesen', 'Komprimiert gelesen', 'Komprimiert gelesen'],
            PdfTextlayer::lesen($pdf->pdf($katalog))->seiten,
        );
    }

    /**
     * Type0 with Identity-H: two bytes per code, the text only through the
     * ToUnicode map - `bfchar`, both forms of `bfrange`, a ligature.
     */
    public function testACompositeFontReadsThroughItsToUnicodeMap(): void
    {
        $cmap = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n"
            . "1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
            . "2 beginbfchar <0001> <00DC> <0004> <00660069> endbfchar\n"
            . "2 beginbfrange <0010> <0019> <0030> <0002> <0003> [<0062> <0065>] endbfrange\n"
            . "endcmap CMapName currentdict /CMap defineresource pop end end";
        $pdf = new PdfBaukasten();
        $toUnicode = $pdf->stream($cmap, komprimiert: true);
        $cid = $pdf->objekt('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /ABCDEF+Muster /DW 600 /W [1 [700 500 500] 16 25 550] >>');
        $schrift = sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /ABCDEF+Muster /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $cid, $toUnicode);

        $text = self::eineSeite($pdf, 'BT /F1 10 Tf 72 800 Td <000100020003> Tj [<0004> -300 <00110010>] TJ ET', $schrift);

        self::assertSame('Übefi 10', $text);
    }

    public function testACompositeFontWithoutToUnicodeIsNotUsable(): void
    {
        $pdf = new PdfBaukasten();
        $cid = $pdf->objekt('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Muster >>');
        $schrift = sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /Muster /Encoding /Identity-H /DescendantFonts [%d 0 R] >>', $cid);
        $inhalt = 'BT /F1 10 Tf 72 800 Td <' . str_repeat('002A0031', 150) . '> Tj ET';
        $katalog = self::seiteMitSchrift($pdf, $inhalt, $schrift);

        $textlayer = PdfTextlayer::lesen($pdf->pdf($katalog));

        self::assertSame(TextlayerBefund::Gelesen, $textlayer->befund);
        self::assertSame(300, $textlayer->zeichen());
        self::assertSame(0.0, $textlayer->buchstabenanteil());
        self::assertFalse($textlayer->brauchbar());
    }

    public function testDifferencesNameTheGlyphs(): void
    {
        $schrift = '<< /Type /Font /Subtype /Type1 /BaseFont /ABCDEF+Muster /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding'
            . ' /Differences [128 /Euro /adieresis /germandbls /uni00DC /f_i /a.sc /gibtsnicht] >> >>';

        $text = PdfTextlayer::lesen(PdfBaukasten::seite("BT /F1 12 Tf 72 800 Td (\\200\\201\\202\\203\\204\\205\\206 OK) Tj ET", $schrift))->text();

        self::assertSame("€äßÜfia\u{FFFD} OK", $text);
    }

    public function testMacRomanEncoding(): void
    {
        $schrift = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /MacRomanEncoding >>';

        self::assertSame('äöüß', PdfTextlayer::lesen(PdfBaukasten::seite('BT /F1 12 Tf 72 800 Td (\212\232\237\247) Tj ET', $schrift))->text());
    }

    /**
     * Adobe's generators write `<0000> <FFFF>` as the codespace of the
     * ToUnicode map of simple fonts too - their codes stay one byte long.
     */
    public function testASimpleFontStaysOneBytePerCodeWhateverItsToUnicodeCodespaceSays(): void
    {
        $pdf = new PdfBaukasten();
        $toUnicode = $pdf->stream("begincmap 1 begincodespacerange <0000> <FFFF> endcodespacerange\n1 beginbfchar <41> <0058> endbfchar endcmap");
        $schrift = sprintf('<< /Type /Font /Subtype /TrueType /BaseFont /ABCDEF+Muster /Encoding /WinAnsiEncoding /ToUnicode %d 0 R >>', $toUnicode);

        self::assertSame('XBC', self::eineSeite($pdf, 'BT /F1 12 Tf 72 800 Td (ABC) Tj ET', $schrift));
    }

    public function testObjectsInObjectStreams(): void
    {
        $pdf = new PdfBaukasten();
        $katalog = $pdf->reservieren();
        $seiten = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $inhalt = $pdf->stream('BT /F1 12 Tf 72 800 Td (Aus dem Objekt-Stream) Tj ET', komprimiert: true);
        $seite = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $seiten, $schrift, $inhalt));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $seiten);
        $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $seiten), $katalog);
        foreach ([$katalog, $seiten, $schrift, $seite] as $nummer) {
            $pdf->packen($nummer);
        }

        $datei = $pdf->pdf($katalog);

        self::assertStringNotContainsString('/Type /Catalog', $datei, 'the catalog really is inside the compressed object stream');
        self::assertSame('Aus dem Objekt-Stream', PdfTextlayer::lesen($datei)->text());
    }

    /**
     * /Length written after the stream as a reference, and an image whose
     * bytes happen to contain "n 0 obj" of an existing object - skipped by
     * its /Length, it replaces nothing.
     */
    public function testIndirectLengthAndObjectHeadersInsideStreamData(): void
    {
        $pdf = new PdfBaukasten();
        $katalog = $pdf->reservieren();
        $seiten = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $inhaltNr = $pdf->reservieren();
        $laengeNr = $pdf->reservieren();
        $inhalt = 'BT /F1 12 Tf 72 800 Td (Echt) Tj ET';
        $pdf->objekt(sprintf("<< /Length %d 0 R >>\nstream\n%s\nendstream", $laengeNr, $inhalt), $inhaltNr);
        $pdf->objekt((string) strlen($inhalt), $laengeNr);
        $bild = $pdf->stream(
            sprintf("\xFF\xD8\n%d 0 obj\n<< /Length 33 >>\nstream\nBT /F1 12 Tf (Phantom) Tj ET\nendstream\nendobj\n\xFF\xD9", $inhaltNr),
            '/Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /DCTDecode',
        );
        $seite = $pdf->objekt(sprintf(
            '<< /Type /Page /Parent %d 0 R /Resources << /Font << /F1 %d 0 R >> /XObject << /Im1 %d 0 R >> >> /Contents %d 0 R >>',
            $seiten,
            $schrift,
            $bild,
            $inhaltNr,
        ));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $seiten);
        $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $seiten), $katalog);

        self::assertSame('Echt', PdfTextlayer::lesen($pdf->pdf($katalog))->text());
    }

    public function testFormXObjectsAreEnteredImagesAndInlineImagesAreNot(): void
    {
        $pdf = new PdfBaukasten();
        $katalog = $pdf->reservieren();
        $seiten = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $form = $pdf->stream(
            'BT /F9 12 Tf 0 0 Td (Im Formular) Tj ET',
            sprintf('/Type /XObject /Subtype /Form /BBox [0 0 500 100] /Matrix [1 0 0 1 72 700] /Resources << /Font << /F9 %d 0 R >> >>', $schrift),
            komprimiert: true,
        );
        // Not decodable at all: decoding it would fail - it must never be tried.
        $bild = $pdf->stream("kein zlib \x00\x01\x02", '/Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /FlateDecode');
        $inhalt = $pdf->stream(
            "q 1 0 0 1 0 0 cm /Fm1 Do Q q 100 0 0 100 0 0 cm /Im1 Do Q\n"
            . "BI /W 4 /H 1 /BPC 8 /CS /G ID \x00(Falsch) Tj\xFF EI\n"
            . 'BT /F1 12 Tf 72 600 Td (Nach dem Bild) Tj ET',
        );
        $seite = $pdf->objekt(sprintf(
            '<< /Type /Page /Parent %d 0 R /Resources << /Font << /F1 %d 0 R >> /XObject << /Fm1 %d 0 R /Im1 %d 0 R >> >> /Contents %d 0 R >>',
            $seiten,
            $schrift,
            $form,
            $bild,
            $inhalt,
        ));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $seiten);
        $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $seiten), $katalog);

        self::assertSame("Im Formular\nNach dem Bild", PdfTextlayer::lesen($pdf->pdf($katalog))->text());
    }

    /**
     * The order of the page tree, not of the object numbers; resources
     * inherited from a parent node.
     */
    public function testPagesInTreeOrderWithInheritedResources(): void
    {
        $pdf = new PdfBaukasten();
        $drei = $pdf->stream('BT /F1 12 Tf 72 800 Td (Seite drei) Tj ET');
        $zwei = $pdf->stream('BT /F1 12 Tf 72 800 Td (Seite zwei) Tj ET');
        $eins = $pdf->stream('BT /F1 12 Tf 72 800 Td (Seite eins) Tj ET');
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $wurzel = $pdf->reservieren();
        $unterbaum = $pdf->reservieren();
        $s3 = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $wurzel, $drei));
        $s2 = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $unterbaum, $zwei));
        $s1 = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $unterbaum, $eins));
        $pdf->objekt(sprintf('<< /Type /Pages /Parent %d 0 R /Kids [%d 0 R %d 0 R] /Count 2 >>', $wurzel, $s1, $s2), $unterbaum);
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R %d 0 R] /Count 3 /Resources << /Font << /F1 %d 0 R >> >> >>', $unterbaum, $s3, $schrift), $wurzel);
        $katalog = $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $wurzel));

        self::assertSame(['Seite eins', 'Seite zwei', 'Seite drei'], PdfTextlayer::lesen($pdf->pdf($katalog))->seiten);
    }

    public function testAtMostTheFirstPagesAreRead(): void
    {
        $pdf = new PdfBaukasten();
        $wurzel = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $inhalt = $pdf->stream('BT /F1 12 Tf 72 800 Td (Seite) Tj ET');
        $kinder = [];
        for ($i = 0; $i < PdfTextlayer::MAX_SEITEN + 5; $i++) {
            $kinder[] = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $wurzel, $inhalt)) . ' 0 R';
        }
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%s] /Count %d /Resources << /Font << /F1 %d 0 R >> >> >>', implode(' ', $kinder), count($kinder), $schrift), $wurzel);
        $katalog = $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $wurzel));

        self::assertCount(PdfTextlayer::MAX_SEITEN, PdfTextlayer::lesen($pdf->pdf($katalog))->seiten);
    }

    public function testAnIncrementalUpdateReplacesTheObject(): void
    {
        $original = PdfBaukasten::seite('BT /F1 12 Tf 72 800 Td (Alte Fassung) Tj ET');
        self::assertSame(1, preg_match('/(\d+) 0 obj\n<<\s*\/Length \d+ >>\nstream\nBT/', $original, $treffer));
        $neu = 'BT /F1 12 Tf 72 800 Td (Neue Fassung) Tj ET';
        $update = sprintf("%d 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%%%EOF\n", (int) $treffer[1], strlen($neu), $neu);

        self::assertSame('Neue Fassung', PdfTextlayer::lesen($original . $update)->text());
    }

    public function testAnEncryptedPdfHasNoText(): void
    {
        $pdf = new PdfBaukasten();
        $verschluesselung = $pdf->objekt('<< /Filter /Standard /V 2 /R 3 /Length 128 /P -1068 /O <00> /U <00> >>');
        $katalog = self::seiteMitSchrift($pdf, 'BT /F1 12 Tf 72 800 Td (Chiffrat) Tj ET', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');

        $textlayer = PdfTextlayer::lesen($pdf->pdf($katalog, sprintf('/Encrypt %d 0 R', $verschluesselung)));

        self::assertSame(TextlayerBefund::Verschluesselt, $textlayer->befund);
        self::assertSame([], $textlayer->seiten);
        self::assertFalse($textlayer->brauchbar());
    }

    /**
     * Whatever is wrong with the file, the reader ends with a result -
     * PHPUnit's failOnWarning makes sure it ends without a warning too.
     */
    public function testDamagedFilesEndWithoutTextAndWithoutAWarning(): void
    {
        $gut = file_get_contents(self::FIXTURES . '/rechnung-eingebettete-schrift.pdf');
        self::assertIsString($gut);

        self::assertSame(TextlayerBefund::Defekt, PdfTextlayer::lesen('Hallo Welt')->befund);
        self::assertSame(TextlayerBefund::Defekt, PdfTextlayer::lesen('')->befund);
        self::assertSame(TextlayerBefund::Defekt, PdfTextlayer::lesen("%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj")->befund);
        // Cut off anywhere: what is there may still be read, nothing breaks.
        for ($anteil = 0.05; $anteil < 1.0; $anteil += 0.05) {
            $abgeschnitten = PdfTextlayer::lesen(substr($gut, 0, (int) (strlen($gut) * $anteil)));
            self::assertContains($abgeschnitten->befund, [TextlayerBefund::Gelesen, TextlayerBefund::Defekt]);
        }
        self::assertFalse(PdfTextlayer::lesen(substr($gut, 0, 2000))->brauchbar());

        // Broken zlib, unbalanced strings and dictionaries, a cyclic page tree.
        $kaputt = PdfBaukasten::seite('BT /F1 12 Tf (offen Tj [<< /A ET');
        self::assertSame([''], PdfTextlayer::lesen($kaputt)->seiten);
        $flate = (string) preg_replace('/stream\nx\x9c/', "stream\nxx", PdfBaukasten::seite('BT /F1 12 Tf (x) Tj ET', komprimiert: true));
        self::assertSame([''], PdfTextlayer::lesen($flate)->seiten);
        $zyklus = "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [2 0 R 3 0 R] >> endobj\n"
            . "3 0 obj << /Type /Pages /Kids [2 0 R] >> endobj\ntrailer << /Root 1 0 R >>";
        self::assertSame(TextlayerBefund::Defekt, PdfTextlayer::lesen($zyklus)->befund);
    }

    /** A small stream that inflates beyond the limit stops the reader before the memory is spent. */
    public function testADecompressionBombIsStopped(): void
    {
        $bombe = 'BT /F1 12 Tf 72 800 Td (x) Tj ET' . str_repeat(' ', PdfTextlayer::MAX_DEKODIERT);

        $textlayer = PdfTextlayer::lesen(PdfBaukasten::seite($bombe, komprimiert: true));

        self::assertSame(TextlayerBefund::ZuGross, $textlayer->befund);
        self::assertSame([], $textlayer->seiten);
    }

    /** What this application builds from photographed receipts (PdfAusBildern) has no text layer. */
    public function testAScanHasNoUsableText(): void
    {
        $pdf = implode('', iterator_to_array(PdfAusBildern::erzeuge([FakeJpeg::bauen(breite: 400, hoehe: 600), FakeJpeg::bauen(breite: 400, hoehe: 600)]), false));

        $textlayer = PdfTextlayer::lesen($pdf);

        self::assertSame(TextlayerBefund::Gelesen, $textlayer->befund);
        self::assertSame(['', ''], $textlayer->seiten);
        self::assertFalse($textlayer->brauchbar());
    }

    /** A standard font without embedding (ReportLab), compressed content. */
    public function testFixtureWithAStandardFont(): void
    {
        $textlayer = self::fixture('rechnung-standardschrift.pdf');

        self::assertTrue($textlayer->brauchbar());
        self::assertCount(1, $textlayer->seiten);
        foreach ([
            'Musterfirma Getränke KG · Beispielweg 3 · 12345 Musterstadt',
            'Vereinsstraße 7',
            'Rechnung Nr. 2026-0815',
            '1 Apfelschorle 0,5 l (Kiste) 6 9,90 € 59,40 €',
            'Gesamtbetrag 119,00 €',
            'IBAN DE02 1203 0000 0000 2020 51 – Verwendungszweck: Rechnung 2026-0815',
        ] as $zeile) {
            self::assertContains($zeile, explode("\n", $textlayer->text()));
        }
    }

    /** Embedded TrueType subsets with ToUnicode maps (LibreOffice). */
    public function testFixtureWithEmbeddedFonts(): void
    {
        $textlayer = self::fixture('rechnung-eingebettete-schrift.pdf');

        self::assertTrue($textlayer->brauchbar());
        foreach ([
            'Rechnung Nr. RE-2026-0042',
            '2 Fußball Größe 5 – Spielball 2 0,00 € 0,00 €',
            'zzgl. 19 % Umsatzsteuer: 78,66 €',
            'Gesamtbetrag brutto: 492,66 €',
        ] as $zeile) {
            self::assertContains($zeile, explode("\n", $textlayer->text()));
        }
    }

    // ------------------------------------------------------------ helpers

    private static function fixture(string $name): Textlayer
    {
        $pdf = file_get_contents(self::FIXTURES . '/' . $name);
        self::assertIsString($pdf);

        return PdfTextlayer::lesen($pdf);
    }

    /** One page with the font `/F1`, read back. */
    private static function eineSeite(PdfBaukasten $pdf, string $inhalt, string $schrift): string
    {
        return PdfTextlayer::lesen($pdf->pdf(self::seiteMitSchrift($pdf, $inhalt, $schrift)))->text();
    }

    /** Adds catalog, page tree and one page with `/F1` = $schrift; returns the catalog. */
    private static function seiteMitSchrift(PdfBaukasten $pdf, string $inhalt, string $schrift): int
    {
        $wurzel = $pdf->reservieren();
        $f1 = $pdf->objekt($schrift);
        $inhaltNr = $pdf->stream($inhalt);
        $seite = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $wurzel, $f1, $inhaltNr));
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $seite), $wurzel);

        return $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $wurzel));
    }

    /**
     * Three pages, one per content stream, Helvetica as `/F1`.
     *
     * @param list<int> $inhalte
     */
    private static function dreiSeiten(PdfBaukasten $pdf, array $inhalte): int
    {
        $wurzel = $pdf->reservieren();
        $schrift = $pdf->objekt('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $kinder = [];
        foreach ($inhalte as $inhalt) {
            $kinder[] = $pdf->objekt(sprintf('<< /Type /Page /Parent %d 0 R /Contents %d 0 R >>', $wurzel, $inhalt)) . ' 0 R';
        }
        $pdf->objekt(sprintf('<< /Type /Pages /Kids [%s] /Count %d /Resources << /Font << /F1 %d 0 R >> >> >>', implode(' ', $kinder), count($kinder), $schrift), $wurzel);

        return $pdf->objekt(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $wurzel));
    }

    private static function ascii85(string $daten): string
    {
        $ergebnis = '';
        foreach (str_split($daten, 4) as $gruppe) {
            $laenge = strlen($gruppe);
            $zahl = unpack('N', str_pad($gruppe, 4, "\0"))[1];
            $ziffern = '';
            for ($i = 0; $i < 5; $i++) {
                $ziffern = chr(33 + $zahl % 85) . $ziffern;
                $zahl = intdiv($zahl, 85);
            }
            $ergebnis .= substr($ziffern, 0, $laenge + 1);
        }

        return $ergebnis;
    }

    /** PNG predictor "Up" (type 2) over rows of $spalten bytes. */
    private static function pngUp(string $daten, int $spalten): string
    {
        $ergebnis = '';
        $vorher = array_fill(0, $spalten, 0);
        foreach (str_split(str_pad($daten, (int) ceil(strlen($daten) / $spalten) * $spalten, ' '), $spalten) as $zeile) {
            $bytes = array_values(unpack('C*', $zeile) ?: []);
            $ergebnis .= "\x02" . pack('C*', ...array_map(static fn (int $b, int $o): int => ($b - $o) & 0xFF, $bytes, $vorher));
            $vorher = $bytes;
        }

        return $ergebnis;
    }
}
