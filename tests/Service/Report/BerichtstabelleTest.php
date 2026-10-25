<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Service\Report\Berichtstabelle;
use App\Service\Report\KassenpruefungCsv;
use App\Service\Report\Zeitraum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CSV format of the report downloads (docs/spec/05-auswertung-und-
 * export.md section 1 "Export CSV", "Pflicht-Tests": BOM, separator, number
 * format; issue #74) and the formula guard on text cells.
 */
final class BerichtstabelleTest extends TestCase
{
    public function testUtf8WithBomSemicolonCrlfAndGermanAmounts(): void
    {
        $csv = new Berichtstabelle(['Konto', 'Betrag', 'Leer'], [
            ['Girokonto Süd', 123456, null],
            ['Kasse', -1250, 'x'],
            ['Null', 0, ''],
            ['Groß', 123456789, null],
        ])->csv();

        self::assertSame(
            "\u{FEFF}Konto;Betrag;Leer\r\n"
            . "Girokonto Süd;1.234,56;\r\n"
            . "Kasse;-12,50;x\r\n"
            . "Null;0,00;\r\n"
            . "Groß;1.234.567,89;\r\n",
            $csv,
        );
    }

    public function testSeparatorsQuotesAndLineBreaksAreQuoted(): void
    {
        $csv = new Berichtstabelle(['A'], [['a;b'], ['sagt "hallo"'], ["zwei\nZeilen"]])->csv();

        self::assertSame("\u{FEFF}A\r\n\"a;b\"\r\n\"sagt \"\"hallo\"\"\"\r\n\"zwei\nZeilen\"\r\n", $csv);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formeln(): iterable
    {
        yield 'equals' => ['=SUMME(A1:A9)', "'=SUMME(A1:A9)"];
        yield 'plus' => ['+49 170', "'+49 170"];
        yield 'minus' => ['-1+1', "'-1+1"];
        yield 'at' => ['@cmd', "'@cmd"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "\"'\r=1\""];
        yield 'harmless' => ['Ballhaus = gut', 'Ballhaus = gut'];
    }

    #[DataProvider('formeln')]
    public function testTextThatStartsLikeAFormulaIsNeutralised(string $text, string $zelle): void
    {
        self::assertSame("\u{FEFF}T\r\n" . $zelle . "\r\n", new Berichtstabelle(['T'], [[$text]])->csv());
    }

    public function testAnAmountIsNeverGuardedAsAFormula(): void
    {
        self::assertSame("\u{FEFF}B\r\n-0,01\r\n", new Berichtstabelle(['B'], [[-1]])->csv());
    }

    public function testDownloadNamesHoldThePartAndThePeriodOnly(): void
    {
        self::assertSame(
            'kassenpruefung-ohne-beleg-2025-04-01-2025-06-30.csv',
            KassenpruefungCsv::dateiname('ohne-beleg', Zeitraum::quartal(2025, 2)),
        );
        $this->expectException(\InvalidArgumentException::class);
        KassenpruefungCsv::dateiname('../etc', Zeitraum::jahr(2025));
    }
}
