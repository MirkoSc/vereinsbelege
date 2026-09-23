<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\JpegInfo;
use App\Service\Processing\ProcessingException;
use App\Tests\Support\FakeJpeg;
use PHPUnit\Framework\TestCase;

/**
 * Reading a JPEG's own header (issue #26/M4-4, docs/spec/03-erfassung-und-ki.md
 * section 3 "Pflicht-Tests"): pixel size, component count, the Adobe CMYK
 * inversion flag and the EXIF orientation tag - all read from hand-built or
 * GD-produced byte streams, never decoded pixel by pixel.
 */
final class JpegInfoTest extends TestCase
{
    public function testRealJpegFromGdIsRgbWithThreeComponents(): void
    {
        $bild = imagecreatetruecolor(37, 21);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 40, 120, 200));
        ob_start();
        imagejpeg($bild);
        $jpeg = ob_get_clean();
        imagedestroy($bild);

        $info = JpegInfo::aus($jpeg);

        self::assertSame(37, $info->width);
        self::assertSame(21, $info->height);
        self::assertSame(3, $info->components);
        self::assertFalse($info->cmykInvertiert);
        self::assertSame(1, $info->orientierung);
    }

    public function testGrayscaleComponentCountIsRead(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 50, hoehe: 30, komponenten: 1));

        self::assertSame(1, $info->components);
        self::assertSame(50, $info->width);
        self::assertSame(30, $info->height);
    }

    public function testExifOrientationLittleEndian(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 10, hoehe: 20, orientierung: 6, littleEndianExif: true));

        self::assertSame(6, $info->orientierung);
    }

    public function testExifOrientationBigEndian(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 10, hoehe: 20, orientierung: 8, littleEndianExif: false));

        self::assertSame(8, $info->orientierung);
    }

    public function testNoExifDefaultsToOrientationOne(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 10, hoehe: 20));

        self::assertSame(1, $info->orientierung);
    }

    public function testAdobeMarkerMarksCmykAsInverted(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 10, hoehe: 10, komponenten: 4, adobe: true));

        self::assertSame(4, $info->components);
        self::assertTrue($info->cmykInvertiert);
    }

    public function testAdobeMarkerWithoutCmykIsNotInverted(): void
    {
        $info = JpegInfo::aus(FakeJpeg::bauen(breite: 10, hoehe: 10, komponenten: 3, adobe: true));

        self::assertFalse($info->cmykInvertiert);
    }

    public function testNotAJpegIsRejected(): void
    {
        $this->expectException(ProcessingException::class);

        JpegInfo::aus('not a jpeg at all');
    }

    public function testTruncatedJpegIsRejected(): void
    {
        $this->expectException(ProcessingException::class);

        JpegInfo::aus(substr(FakeJpeg::bauen(breite: 10, hoehe: 10), 0, 5));
    }
}
