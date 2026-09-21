<?php

declare(strict_types=1);

namespace App\Tests\Service\Upload;

use App\Service\Upload\MagicBytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first bytes decide what a file is - never the type the browser claimed
 * (docs/spec/03-erfassung-und-ki.md section 4).
 */
final class MagicBytesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function erlaubteTypen(): array
    {
        return [
            'JPEG (JFIF)' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", MagicBytes::JPEG],
            'JPEG (Exif, aus einer Handykamera)' => ["\xFF\xD8\xFF\xE1\x12\x34Exif", MagicBytes::JPEG],
            'PNG' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR", MagicBytes::PNG],
            'PDF' => ['%PDF-1.7' . "\n%\xE2\xE3\xCF\xD3", MagicBytes::PDF],
        ];
    }

    #[DataProvider('erlaubteTypen')]
    public function testTheThreeAcceptedTypesAreRecognised(string $head, string $erwartet): void
    {
        self::assertSame($erwartet, MagicBytes::detect($head));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function abgelehnteInhalte(): array
    {
        return [
            'ZIP (auch ein Backup ist kein Beleg)' => ["PK\x03\x04\x14\x00\x00\x00"],
            'ELF' => ["\x7FELF\x02\x01\x01\x00"],
            'HTML' => ['<!DOCTYPE html><html lang="de">'],
            'Klartext' => ['Rechnung Nr. 4711'],
            'GIF' => ['GIF89a'],
            'HEIC' => ["\x00\x00\x00\x18ftypheic"],
            'leer' => [''],
            'zu kurz für eine Signatur' => ["\xFF\xD8"],
            'PDF-Kopf an falscher Stelle' => ["\n%PDF-1.4"],
        ];
    }

    #[DataProvider('abgelehnteInhalte')]
    public function testEverythingElseIsRefused(string $head): void
    {
        self::assertNull(MagicBytes::detect($head));
    }

    /**
     * The detection may only look at the head, never at the whole file: a
     * 30 MB scan is not read into memory to learn its type.
     */
    public function testTheHeadIsEnoughToDecide(): void
    {
        $kopf = substr("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 10_000), 0, MagicBytes::HEAD_BYTES);

        self::assertSame(MagicBytes::PNG, MagicBytes::detect($kopf));
    }
}
