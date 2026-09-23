<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\JpegInfo;
use App\Service\Processing\PngZuJpeg;
use App\Service\Processing\ProcessingException;
use PHPUnit\Framework\TestCase;

/**
 * The GD fallback that lets App\Service\Document\PdfErzeugung embed a PNG
 * page (issue #26/M4-4, docs/spec/03-erfassung-und-ki.md section 3: "der
 * Server nutzt nur GD als Fallback").
 */
final class PngZuJpegTest extends TestCase
{
    public function testTruecolorPngBecomesAJpegOfTheSameSize(): void
    {
        $bild = imagecreatetruecolor(24, 16);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 10, 200, 90));
        $png = self::alsPng($bild);

        $jpeg = PngZuJpeg::konvertiere($png);

        self::assertTrue(str_starts_with($jpeg, "\xFF\xD8"));
        $info = JpegInfo::aus($jpeg);
        self::assertSame(24, $info->width);
        self::assertSame(16, $info->height);
        self::assertSame(3, $info->components);
    }

    public function testTransparencyIsFlattenedOntoWhite(): void
    {
        $bild = imagecreatetruecolor(4, 4);
        imagesavealpha($bild, true);
        $transparent = imagecolorallocatealpha($bild, 0, 0, 0, 127);
        imagefill($bild, 0, 0, $transparent);
        $png = self::alsPng($bild);

        $jpeg = PngZuJpeg::konvertiere($png);
        $bildAusJpeg = imagecreatefromstring($jpeg);
        self::assertNotFalse($bildAusJpeg);

        $farbe = imagecolorat($bildAusJpeg, 0, 0);
        $r = ($farbe >> 16) & 0xFF;
        $g = ($farbe >> 8) & 0xFF;
        $b = $farbe & 0xFF;
        // JPEG's lossy YCbCr round trip does not land on exactly 255,255,255.
        self::assertGreaterThan(240, $r);
        self::assertGreaterThan(240, $g);
        self::assertGreaterThan(240, $b);
        imagedestroy($bildAusJpeg);
    }

    public function testPaletteImageIsConverted(): void
    {
        $bild = imagecreate(8, 8);
        imagecolorallocate($bild, 255, 0, 0);
        $png = self::alsPng($bild);

        $jpeg = PngZuJpeg::konvertiere($png);

        $info = JpegInfo::aus($jpeg);
        self::assertSame(8, $info->width);
        self::assertSame(8, $info->height);
    }

    public function testPixelCeilingRejectsAHugeImage(): void
    {
        // A 6000x6000 IHDR claim without any real pixel data - large enough
        // to breach PngZuJpeg::MAX_PIXEL without actually allocating it.
        $png = self::pngMitVorgetaeuschterGroesse(6000, 6000);

        $this->expectException(ProcessingException::class);

        PngZuJpeg::konvertiere($png);
    }

    public function testNotAPngIsRejected(): void
    {
        $this->expectException(ProcessingException::class);

        PngZuJpeg::konvertiere('not a png at all');
    }

    private static function alsPng(\GdImage $bild): string
    {
        ob_start();
        imagepng($bild);
        $png = ob_get_clean();
        imagedestroy($bild);

        return $png;
    }

    /**
     * A syntactically valid PNG signature + IHDR chunk claiming a huge size,
     * without the (expensive) real pixel data behind it - getimagesizefromstring()
     * reads only the IHDR, so this is enough to exercise the size guard
     * without allocating hundreds of megabytes in the test itself.
     */
    private static function pngMitVorgetaeuschterGroesse(int $breite, int $hoehe): string
    {
        $ihdrDaten = pack('NNCCCCC', $breite, $hoehe, 8, 2, 0, 0, 0);
        $ihdr = self::pngChunk('IHDR', $ihdrDaten);

        return "\x89PNG\r\n\x1A\n" . $ihdr;
    }

    private static function pngChunk(string $typ, string $daten): string
    {
        $inhalt = $typ . $daten;

        return pack('N', strlen($daten)) . $inhalt . pack('N', crc32($inhalt));
    }
}
