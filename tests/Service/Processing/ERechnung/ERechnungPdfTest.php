<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing\ERechnung;

use App\Service\Processing\ERechnung\ERechnungBefund;
use App\Service\Processing\ERechnung\ERechnungPdf;
use App\Service\Processing\ERechnung\ERechnungSyntax;
use App\Service\Processing\Pdf\PdfTextlayer;
use App\Tests\Support\ERechnungPdfBaukasten;
use App\Tests\Support\PdfBaukasten;
use PHPUnit\Framework\TestCase;

/**
 * The invoice XML a ZUGFeRD/Factur-X PDF carries (issue #46/M7-4,
 * docs/spec/03-erfassung-und-ki.md section 3): found in the name tree or
 * the associated files, the standard names first, other attachments left
 * alone - and a PDF without one, or one this reader cannot open, simply has
 * no e-invoice.
 */
final class ERechnungPdfTest extends TestCase
{
    public function testTheZugferdFixture(): void
    {
        $pdf = (string) file_get_contents(ERechnungLeserTest::FIXTURES . '/zugferd-en16931.pdf');

        $ergebnis = ERechnungPdf::lesen($pdf);

        self::assertSame(ERechnungBefund::Gelesen, $ergebnis->befund);
        self::assertNotNull($ergebnis->rechnung);
        self::assertSame(ERechnungSyntax::Cii, $ergebnis->rechnung->syntax);
        self::assertSame('urn:cen.eu:en16931:2017', $ergebnis->rechnung->profil);
        self::assertSame('RE-2026-0043', $ergebnis->rechnung->nummer);
        self::assertSame(17250, $ergebnis->rechnung->brutto);
        self::assertTrue(PdfTextlayer::lesen($pdf)->brauchbar(), 'the visible page has a usable text layer as well');
    }

    public function testANameTreeWithKidsAndUtf16Names(): void
    {
        $pdf = ERechnungPdfBaukasten::pdf(
            [['Stundenzettel.xml', '<?xml version="1.0"?><zettel/>'], ['ZUGFeRD-invoice.xml', self::xml()]],
            kinder: true,
            nameUtf16: true,
        );

        self::assertSame(ERechnungBefund::Gelesen, ERechnungPdf::lesen($pdf)->befund);
    }

    public function testOnlyTheAssociatedFiles(): void
    {
        $pdf = ERechnungPdfBaukasten::pdf([['factur-x.xml', self::xml()]], nurAf: true);

        self::assertSame(ERechnungBefund::Gelesen, ERechnungPdf::lesen($pdf)->befund);
    }

    public function testTheStandardNameComesFirst(): void
    {
        $andere = str_replace('RE-2026-0042', 'ANDERE-1', self::xml());
        $pdf = ERechnungPdfBaukasten::pdf([['kopie.xml', $andere], ['factur-x.xml', self::xml()]]);

        self::assertSame('RE-2026-0042', ERechnungPdf::lesen($pdf)->rechnung?->nummer);
    }

    public function testAnyXmlAttachmentWhenNoStandardNameIsThere(): void
    {
        $pdf = ERechnungPdfBaukasten::pdf([['notiz.txt', self::xml()], ['rechnung_4711.XML', self::xml()]]);

        self::assertSame(ERechnungBefund::Gelesen, ERechnungPdf::lesen($pdf)->befund);
    }

    public function testAttachmentsThatAreNoInvoice(): void
    {
        $pdf = ERechnungPdfBaukasten::pdf([['notiz.txt', self::xml()], ['zettel.xml', '<zettel/>']]);

        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen($pdf)->befund, 'a .txt is not looked at');
    }

    public function testAPdfWithoutAttachments(): void
    {
        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen(PdfBaukasten::seite('BT /F1 12 Tf 72 800 Td (Rechnung) Tj ET'))->befund);
        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen((string) file_get_contents(__DIR__ . '/../../../fixtures/pdf/rechnung-standardschrift.pdf'))->befund);
    }

    public function testDamagedAndUnsupportedAttachments(): void
    {
        $kaputt = ERechnungPdfBaukasten::pdf([['factur-x.xml', substr(self::xml(), 0, 3000)]]);
        $alt = ERechnungPdfBaukasten::pdf([['ZUGFeRD-invoice.xml', ERechnungLeserTest::fixture('zugferd-1.xml')]]);

        self::assertSame(ERechnungBefund::Defekt, ERechnungPdf::lesen($kaputt)->befund);
        self::assertSame(ERechnungBefund::NichtUnterstuetzt, ERechnungPdf::lesen($alt)->befund);
    }

    public function testEncryptedOrBrokenPdfsHaveNoInvoice(): void
    {
        $verschluesselt = ERechnungPdfBaukasten::pdf([['factur-x.xml', self::xml()]], trailerZusatz: '/Encrypt << /Filter /Standard /V 2 >>');

        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen($verschluesselt)->befund);
        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen('kein PDF')->befund);
        self::assertSame(ERechnungBefund::Keine, ERechnungPdf::lesen("%PDF-1.7\n1 0 obj << /Type /Catalog /Names << /EmbeddedFiles 1 0 R >> >> endobj")->befund);
    }

    /**
     * Within the text layer's decoding budget: an attachment built to
     * expand to more than that is refused, not unpacked.
     */
    public function testADecompressionBombIsTooLarge(): void
    {
        $bombe = '<?xml version="1.0"?><a>' . str_repeat(' ', PdfTextlayer::MAX_DEKODIERT + 1024) . '</a>';
        $pdf = ERechnungPdfBaukasten::pdf([['factur-x.xml', $bombe]]);
        self::assertLessThan(200_000, strlen($pdf));

        self::assertSame(ERechnungBefund::ZuGross, ERechnungPdf::lesen($pdf)->befund);
    }

    private static function xml(): string
    {
        return ERechnungLeserTest::fixture('xrechnung-cii.xml');
    }
}
