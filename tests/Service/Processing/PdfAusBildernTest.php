<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\PdfAusBildern;
use App\Service\Processing\ProcessingException;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\PdfStruktur;
use PHPUnit\Framework\TestCase;

/**
 * PDF assembly (issue #26/M4-4, docs/spec/03-erfassung-und-ki.md section 3,
 * Pflicht-Test "PDF-Erzeugung – Seitenzahl = Bildzahl, gültiges PDF"): the
 * page count always matches the number of images, every JPEG lands in its
 * page unchanged, and the page geometry follows the image (A4 when close
 * enough, the image's own ratio otherwise, EXIF rotation via the matrix
 * only - never by touching the bytes).
 */
final class PdfAusBildernTest extends TestCase
{
    public function testOneImageBecomesAOnePagePdf(): void
    {
        $jpeg = FakeJpeg::bauen(breite: 400, hoehe: 300);

        $struktur = self::bauen([$jpeg]);

        self::assertCount(1, $struktur->seiten);
        self::assertSame($jpeg, $struktur->seiten[0]['jpeg']);
    }

    public function testThreeImagesBecomeAThreePagePdfInOrder(): void
    {
        $jpegs = [
            FakeJpeg::bauen(breite: 100, hoehe: 200),
            FakeJpeg::bauen(breite: 300, hoehe: 150),
            FakeJpeg::bauen(breite: 50, hoehe: 50),
        ];

        $struktur = self::bauen($jpegs);

        self::assertCount(3, $struktur->seiten);
        foreach ($jpegs as $i => $jpeg) {
            self::assertSame($jpeg, $struktur->seiten[$i]['jpeg'], "Seite {$i}");
        }
    }

    public function testAnImageCloseToA4RatioSnapsToExactA4(): void
    {
        // 2100 x 2970 px is exactly the A4 ratio (210:297).
        $struktur = self::bauen([FakeJpeg::bauen(breite: 2100, hoehe: 2970)]);

        $seite = $struktur->seiten[0];
        self::assertEqualsWithDelta(210 * 72 / 25.4, $seite['breite'], 0.01);
        self::assertEqualsWithDelta(297 * 72 / 25.4, $seite['hoehe'], 0.01);
    }

    public function testALongReceiptKeepsItsOwnAspectRatio(): void
    {
        // Far from A4 (a till receipt): fixed A4 width, height follows the image.
        $struktur = self::bauen([FakeJpeg::bauen(breite: 100, hoehe: 1000)]);

        $seite = $struktur->seiten[0];
        $a4Breite = 210 * 72 / 25.4;
        self::assertEqualsWithDelta($a4Breite, $seite['breite'], 0.01);
        self::assertEqualsWithDelta($a4Breite * 10, $seite['hoehe'], 0.01);
    }

    public function testExifOrientationSixProducesAPortraitPageForALandscapeSource(): void
    {
        // Camera-native landscape (300x200) that EXIF orientation 6 says to
        // rotate 90° CW for display - the resulting page must be portrait.
        $struktur = self::bauen([FakeJpeg::bauen(breite: 300, hoehe: 200, orientierung: 6)]);

        $seite = $struktur->seiten[0];
        self::assertLessThan($seite['hoehe'], $seite['breite']);
    }

    public function testInfoDictionaryHasOnlyProducerWithoutATitle(): void
    {
        $struktur = self::bauen([FakeJpeg::bauen(breite: 10, hoehe: 10)]);

        self::assertSame('Vereinsbelege', $struktur->producer);
        self::assertNull($struktur->titel);
    }

    public function testInfoDictionaryCarriesAValidReferenceAsTitle(): void
    {
        $struktur = self::bauen([FakeJpeg::bauen(breite: 10, hoehe: 10)], 'R-2026-0147');

        self::assertSame('R-2026-0147', $struktur->titel);
    }

    public function testAnInvalidTitleIsRejected(): void
    {
        $this->expectException(ProcessingException::class);

        iterator_to_array(PdfAusBildern::erzeuge([FakeJpeg::bauen(breite: 10, hoehe: 10)], 'Rechnung Mueller'));
    }

    public function testAnEmptyPageListIsRejected(): void
    {
        $this->expectException(ProcessingException::class);

        iterator_to_array(PdfAusBildern::erzeuge([]));
    }

    /**
     * @param list<string> $jpegs
     */
    private static function bauen(array $jpegs, ?string $titel = null): PdfStruktur
    {
        $pdf = implode('', iterator_to_array(PdfAusBildern::erzeuge($jpegs, $titel), false));

        return PdfStruktur::analysiere($pdf);
    }
}
