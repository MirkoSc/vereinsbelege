<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\Textlayer;
use App\Service\Processing\TextlayerBefund;
use PHPUnit\Framework\TestCase;

/**
 * Pflicht-Test "Textlayer-Heuristik" (docs/spec/03-erfassung-und-ki.md,
 * issue #45/M7-3): more than 200 characters that are not whitespace, at
 * least half of them letters - only then does the text stand in for the
 * page images.
 */
final class TextlayerTest extends TestCase
{
    public function testMoreThanTwoHundredCharactersAreNeeded(): void
    {
        self::assertFalse(self::gelesen([str_repeat('a', 200)])->brauchbar());
        self::assertTrue(self::gelesen([str_repeat('a', 201)])->brauchbar());
    }

    public function testWhitespaceDoesNotCount(): void
    {
        $text = self::gelesen([str_repeat("Wort \n\t", 40)]);

        self::assertSame(160, $text->zeichen());
        self::assertFalse($text->brauchbar());
    }

    public function testCharactersCountAcrossPages(): void
    {
        $text = self::gelesen([str_repeat('ä', 101), str_repeat('ß', 100)]);

        self::assertSame(201, $text->zeichen());
        self::assertTrue($text->brauchbar());
        self::assertSame(str_repeat('ä', 101) . "\f" . str_repeat('ß', 100), $text->text());
    }

    /** Digits and punctuation are not letters - half of the text has to be. */
    public function testAtLeastHalfMustBeLetters(): void
    {
        $haelfte = self::gelesen([str_repeat('Rechnung', 15) . str_repeat('1.234,56 ', 15)]);
        self::assertSame(0.5, $haelfte->buchstabenanteil());
        self::assertTrue($haelfte->brauchbar());

        $zuWenig = self::gelesen([str_repeat('Rechnung', 14) . str_repeat('1.234,56 ', 16)]);
        self::assertLessThan(0.5, $zuWenig->buchstabenanteil());
        self::assertFalse($zuWenig->brauchbar());
    }

    /** What an unreadable font produces: replacement characters, symbols. */
    public function testReplacementCharactersAndSymbolSoupAreNotUsable(): void
    {
        self::assertFalse(self::gelesen([str_repeat("\u{FFFD}", 300)])->brauchbar());
        self::assertFalse(self::gelesen([str_repeat('#*%&$!', 50)])->brauchbar());
        self::assertSame(0.0, self::gelesen([''])->buchstabenanteil());
    }

    public function testOnlyAReadTextLayerCanBeUsable(): void
    {
        foreach ([TextlayerBefund::Verschluesselt, TextlayerBefund::Defekt, TextlayerBefund::ZuGross] as $befund) {
            self::assertFalse(new Textlayer($befund, [str_repeat('Rechnung ', 50)])->brauchbar(), $befund->value);
        }
    }

    /**
     * @param list<string> $seiten
     */
    private static function gelesen(array $seiten): Textlayer
    {
        return new Textlayer(TextlayerBefund::Gelesen, $seiten);
    }
}
