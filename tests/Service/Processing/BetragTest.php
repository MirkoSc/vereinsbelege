<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Processing\Betrag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Amount parsing and the sum check (issue #37/M6-3, docs/spec/
 * 03-erfassung-und-ki.md "Pflicht-Tests": „Betrags-Parsing („1.234,56",
 * „1234.56", negativ) und Summenprüfung").
 */
final class BetragTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function gueltig(): iterable
    {
        yield 'German with thousands' => ['1.234,56', 123456];
        yield 'English without thousands' => ['1234.56', 123456];
        yield 'English with thousands' => ['1,234.56', 123456];
        yield 'German without thousands' => ['1234,56', 123456];
        yield 'whole euros' => ['12', 1200];
        yield 'one decimal, comma' => ['0,5', 50];
        yield 'one decimal, dot' => ['12.5', 1250];
        yield 'leading comma' => [',99', 99];
        yield 'a German thousand' => ['1.234', 123400];
        yield 'millions' => ['1.234.567,89', 123456789];
        yield 'English millions' => ['1,234,567', 123456700];
        yield 'negative' => ['-12,50', -1250];
        yield 'negative with typographic minus' => ['−1.234,56', -123456];
        yield 'negative behind, as on a till receipt' => ['12,50-', -1250];
        yield 'explicit plus' => ['+3,00', 300];
        yield 'currency sign and spaces' => [' 1 234,56 € ', 123456];
        yield 'currency code' => ['EUR 19,99', 1999];
        yield 'zero' => ['0,00', 0];
        yield 'leading zeros' => ['007,10', 710];
        yield 'maximum' => ['999.999.999,99', Betrag::MAX_CENT];
    }

    #[DataProvider('gueltig')]
    public function testParsesToCents(string $text, int $cent): void
    {
        self::assertSame($cent, Betrag::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltig(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'letters' => ['abc'];
        yield 'three decimals after a comma (ambiguous)' => ['1,234'];
        yield 'three decimals in German' => ['12,345'];
        yield 'separator without digits' => ['12,'];
        yield 'two decimal separators' => ['1,2,3'];
        yield 'bad grouping' => ['12.34.56'];
        yield 'bad grouping before the decimals' => ['1.23,45'];
        yield 'two decimal commas with a dot' => ['1.234,5,6'];
        yield 'sign only' => ['-'];
        yield 'sign twice' => ['--5'];
        yield 'an exponent' => ['1e3'];
        yield 'too large' => ['1.000.000.000,00'];
        yield 'text around' => ['ca. 12,00'];
    }

    #[DataProvider('ungueltig')]
    public function testRefusesWhatIsNoAmount(string $text): void
    {
        self::assertNull(Betrag::parse($text));
    }

    public function testFormatsGermanWithThousandsSeparators(): void
    {
        self::assertSame('1.234,56', Betrag::format(123456));
        self::assertSame('0,05', Betrag::format(5));
        self::assertSame('-0,05', Betrag::format(-5));
        self::assertSame('-1.234.567,89', Betrag::format(-123456789));
        self::assertSame('12,00', Betrag::format(1200));
    }

    public function testFormatAndParseRoundTrip(): void
    {
        foreach ([0, 1, 99, 100, 123456, -1250, Betrag::MAX_CENT] as $cent) {
            self::assertSame($cent, Betrag::parse(Betrag::format($cent)), (string) $cent);
        }
    }

    public function testSumOfNetAndTaxesMatchesGross(): void
    {
        self::assertTrue(Betrag::summePasst(10000, [1900], 11900));
        self::assertTrue(Betrag::summePasst(10374, [1971], 12345));
        // Two rates, each rounded on the receipt: two cents of slack.
        self::assertTrue(Betrag::summePasst(10000, [1900, 700], 12602));
        // Without taxes net must equal gross (up to a cent).
        self::assertTrue(Betrag::summePasst(5000, [], 5000));
        // A credit note: everything negative.
        self::assertTrue(Betrag::summePasst(-10000, [-1900], -11900));
    }

    public function testSumThatDoesNotMatchIsReported(): void
    {
        self::assertFalse(Betrag::summePasst(10000, [1900], 12000));
        self::assertFalse(Betrag::summePasst(10000, [], 11900));
        self::assertFalse(Betrag::summePasst(10000, [1900, 700], 12603));
    }

    public function testWithoutNetThereIsNothingToCheck(): void
    {
        self::assertTrue(Betrag::summePasst(null, [], 11900));
        self::assertTrue(Betrag::summePasst(null, [1900], 99999));
    }

    public function testTaxRates(): void
    {
        self::assertSame('19', Betrag::prozent('19'));
        self::assertSame('7', Betrag::prozent('7,00 %'));
        self::assertSame('5.5', Betrag::prozent('5,5'));
        self::assertSame('10.7', Betrag::prozent('10.70'));
        self::assertSame('0', Betrag::prozent('0'));
        self::assertSame('100', Betrag::prozent('100'));

        foreach (['', 'x', '-7', '100,5', '101', '19,999', '1.000'] as $ungueltig) {
            self::assertNull(Betrag::prozent($ungueltig), $ungueltig);
        }
    }
}
