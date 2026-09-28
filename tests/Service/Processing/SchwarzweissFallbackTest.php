<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\JpegInfo;
use App\Service\Processing\ProcessingException;
use App\Service\Processing\SchwarzweissFallback;
use App\Tests\Support\FakeJpeg;
use PHPUnit\Framework\TestCase;

/**
 * The server fallback for a page the browser did not process (issue #34/M5-4,
 * docs/spec/03-erfassung-und-ki.md section 2: "GD graustufen + globale
 * Schwelle; kein Entzerren").
 */
final class SchwarzweissFallbackTest extends TestCase
{
    public function testAPhotoBecomesAPureBlackAndWhiteJpeg(): void
    {
        // Grey-ish paper with dark "ink" - neither is black or white yet.
        $bild = imagecreatetruecolor(60, 40);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 190, 180, 170));
        imagefilledrectangle($bild, 10, 10, 30, 20, imagecolorallocate($bild, 60, 50, 70));

        $jpeg = SchwarzweissFallback::aufbereiten(self::alsJpeg($bild));

        self::assertTrue(str_starts_with($jpeg, "\xFF\xD8"));
        $ergebnis = imagecreatefromstring($jpeg);
        self::assertNotFalse($ergebnis);
        self::assertSame(60, imagesx($ergebnis));
        self::assertSame(40, imagesy($ergebnis));
        // JPEG blurs the edge between the two, so sample well inside each.
        self::assertLessThan(30, self::grau($ergebnis, 20, 15), 'Tinte wird schwarz');
        self::assertGreaterThan(225, self::grau($ergebnis, 50, 30), 'Papier wird weiß');
        self::assertGreaterThan(225, self::grau($ergebnis, 2, 2));
    }

    public function testADimPhotoIsNotThresholdedToAllBlack(): void
    {
        // Everything below 128: a fixed global threshold would give black.
        $bild = imagecreatetruecolor(40, 40);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 110, 110, 110));
        imagefilledrectangle($bild, 5, 5, 15, 15, imagecolorallocate($bild, 20, 20, 20));

        $ergebnis = imagecreatefromstring(SchwarzweissFallback::aufbereiten(self::alsJpeg($bild)));
        self::assertNotFalse($ergebnis);

        self::assertLessThan(30, self::grau($ergebnis, 10, 10));
        self::assertGreaterThan(225, self::grau($ergebnis, 30, 30));
    }

    public function testTheLongEdgeIsCappedAtTheBrowsersOutputSize(): void
    {
        $bild = imagecreatetruecolor(3000, 1500);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 255, 255, 255));

        $info = JpegInfo::aus(SchwarzweissFallback::aufbereiten(self::alsJpeg($bild)));

        self::assertSame(SchwarzweissFallback::MAX_KANTE, $info->width);
        self::assertSame(1240, $info->height);
    }

    public function testTheExifOrientationIsAppliedToThePixels(): void
    {
        // Stored landscape with a black block top left; orientation 6 means
        // "turn 90° clockwise to view", so the block ends up top right.
        $bild = imagecreatetruecolor(60, 40);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 255, 255, 255));
        imagefilledrectangle($bild, 0, 0, 14, 9, imagecolorallocate($bild, 0, 0, 0));
        $jpeg = FakeJpeg::mitOrientierung(self::alsJpeg($bild), 6);
        self::assertSame(6, JpegInfo::aus($jpeg)->orientierung);

        $ausgabe = SchwarzweissFallback::aufbereiten($jpeg);
        $info = JpegInfo::aus($ausgabe);
        $ergebnis = imagecreatefromstring($ausgabe);
        self::assertNotFalse($ergebnis);

        self::assertSame(40, $info->width);
        self::assertSame(60, $info->height);
        self::assertSame(1, $info->orientierung, 'die Ausgabe trägt keine Drehung mehr');
        self::assertLessThan(30, self::grau($ergebnis, 36, 4), 'oben rechts');
        self::assertGreaterThan(225, self::grau($ergebnis, 4, 4), 'oben links');
    }

    public function testAPngWithTransparencyIsFlattenedOntoWhite(): void
    {
        $bild = imagecreatetruecolor(20, 20);
        imagesavealpha($bild, true);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        imagefilledrectangle($bild, 0, 0, 9, 19, imagecolorallocate($bild, 0, 0, 0));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();

        $ergebnis = imagecreatefromstring(SchwarzweissFallback::aufbereiten($png));
        self::assertNotFalse($ergebnis);

        self::assertLessThan(30, self::grau($ergebnis, 3, 10));
        self::assertGreaterThan(225, self::grau($ergebnis, 16, 10));
    }

    public function testOtsuSeparatesTwoPeaks(): void
    {
        $histogramm = array_fill(0, 256, 0);
        $histogramm[40] = 100;
        $histogramm[200] = 300;

        $schwelle = SchwarzweissFallback::otsu($histogramm);

        self::assertGreaterThan(40, $schwelle);
        self::assertLessThanOrEqual(200, $schwelle);
    }

    public function testOtsuOfAUniformPageKeepsItWhite(): void
    {
        $histogramm = array_fill(0, 256, 0);
        $histogramm[90] = 1000;

        self::assertSame(0, SchwarzweissFallback::otsu($histogramm));
        self::assertSame(0, SchwarzweissFallback::otsu(array_fill(0, 256, 0)));
    }

    public function testPixelCeilingRejectsAHugeImageBeforeDecoding(): void
    {
        // A JPEG header announcing 10000 x 10000 pixels, no pixel data.
        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Bild ist zu groß.');
        SchwarzweissFallback::aufbereiten(FakeJpeg::bauen(10000, 10000));
    }

    public function testAPdfIsRejected(): void
    {
        $this->expectException(ProcessingException::class);
        SchwarzweissFallback::aufbereiten("%PDF-1.7\n%âãÏÓ\n");
    }

    private static function alsJpeg(\GdImage $bild): string
    {
        ob_start();
        imagejpeg($bild, quality: 95);

        return (string) ob_get_clean();
    }

    private static function grau(\GdImage $bild, int $x, int $y): int
    {
        return imagecolorat($bild, $x, $y) & 0xFF;
    }
}
