<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Service\Export\Csv;
use App\Service\Export\PfadMuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV as Excel opens it in Germany (issue #76/M12-2, docs/spec/
 * 05-auswertung-und-export.md "Pflicht-Tests": CSV-Format - BOM,
 * Trennzeichen, Zahlenformat). All values are made up.
 */
final class CsvTest extends TestCase
{
    public function testTheBomIsUtf8(): void
    {
        self::assertSame("\xEF\xBB\xBF", Csv::BOM);
    }

    public function testSemicolonSeparatedWithCrlf(): void
    {
        self::assertSame("Datum;Lieferant;Brutto\r\n", Csv::zeile(['Datum', 'Lieferant', 'Brutto']));
        self::assertSame(";;\r\n", Csv::zeile(['', '', '']));
    }

    public function testQuotesOnlyWhatNeedsIt(): void
    {
        self::assertSame(
            "\"Müller; Söhne\";\"Sag \"\"Hallo\"\"\";\"Zeile 1\nZeile 2\";Bäckerei, Groß\r\n",
            Csv::zeile(['Müller; Söhne', 'Sag "Hallo"', "Zeile 1\nZeile 2", 'Bäckerei, Groß']),
        );
    }

    public function testBrokenUtf8IsScrubbed(): void
    {
        self::assertTrue(mb_check_encoding(Csv::zeile(["Caf\xC3"]), 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formeln(): iterable
    {
        yield 'equals' => ['=HYPERLINK("http://example.org")', '\'=HYPERLINK("http://example.org")'];
        yield 'plus' => ['+49 123', "'+49 123"];
        yield 'minus' => ['-1+1', "'-1+1"];
        yield 'at' => ['@SUMME(A1)', "'@SUMME(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'plain text' => ['Bauhaus', 'Bauhaus'];
        yield 'equals inside' => ['A=B', 'A=B'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('formeln')]
    public function testTextCellsCannotStartAFormula(string $wert, string $erwartet): void
    {
        self::assertSame($erwartet, Csv::text($wert));
    }

    /**
     * The "Brutto" column: German format, integer cents, a foreign
     * currency with its code.
     *
     * @return iterable<string, array{int, string, string}>
     */
    public static function betraege(): iterable
    {
        yield 'thousands' => [123456, 'EUR', '1.234,56'];
        yield 'cents only' => [5, 'EUR', '0,05'];
        yield 'negative' => [-1250, 'EUR', '-12,50'];
        yield 'million' => [100_000_000, 'EUR', '1.000.000,00'];
        yield 'foreign currency' => [1200, 'USD', '12,00 USD'];
        yield 'currency in lower case' => [1200, 'chf', '12,00 CHF'];
    }

    #[DataProvider('betraege')]
    public function testAmountsAreInGermanFormat(int $cent, string $waehrung, string $erwartet): void
    {
        self::assertSame($erwartet, PfadMuster::betrag($cent, $waehrung));
    }
}
