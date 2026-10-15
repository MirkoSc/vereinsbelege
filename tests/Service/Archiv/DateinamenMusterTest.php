<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiv;

use App\Service\Archiv\DateinamenMuster;
use App\Service\Archiv\UngueltigesDateinamenMuster;
use App\Service\Export\PfadMuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The file name parser of the archive import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3 and "Pflicht-Tests":
 * Archiv-Dateinamen-Parser - Varianten, Suffix, ungültige Daten → keine
 * Vorbelegung). All names are made up.
 */
final class DateinamenMusterTest extends TestCase
{
    private const int BIS_JAHR = 2027;

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function varianten(): iterable
    {
        yield 'plain' => ['Bauhaus 03.02.2026.pdf', 'Bauhaus', '2026-02-03'];
        yield 'upper case extension' => ['Bauhaus 03.02.2026.PDF', 'Bauhaus', '2026-02-03'];
        yield 'single digit day and month' => ['Bauhaus 3.2.2026.pdf', 'Bauhaus', '2026-02-03'];
        yield 'several words' => ['Stadtwerke Musterstadt 15.01.2026.pdf', 'Stadtwerke Musterstadt', '2026-01-15'];
        yield 'umlauts and legal form' => ['Getränke Müller GmbH & Co. KG 01.12.2025.pdf', 'Getränke Müller GmbH & Co. KG', '2025-12-01'];
        yield 'suffix of the export' => ['Bauhaus 17.05.2026 (2).pdf', 'Bauhaus', '2026-05-17'];
        yield 'suffix without space' => ['Bauhaus 17.05.2026(13).pdf', 'Bauhaus', '2026-05-17'];
        yield 'underscores' => ['Getraenke_Mueller_03.02.2026.pdf', 'Getraenke Mueller', '2026-02-03'];
        yield 'double spaces' => ['Musterbau  GmbH   03.02.2026.pdf', 'Musterbau GmbH', '2026-02-03'];
        yield 'jpeg' => ['Kiosk am Platz 30.06.2026.jpg', 'Kiosk am Platz', '2026-06-30'];
        yield 'without extension' => ['Bauhaus 03.02.2026', 'Bauhaus', '2026-02-03'];
        yield 'dash before the date' => ['Bauhaus - 03.02.2026.pdf', 'Bauhaus', '2026-02-03'];
    }

    #[DataProvider('varianten')]
    public function testTheDefaultPatternReadsSupplierAndDate(string $datei, string $lieferant, string $datum): void
    {
        $treffer = DateinamenMuster::standard()->lies($datei, self::BIS_JAHR);

        self::assertNotNull($treffer);
        self::assertSame($lieferant, $treffer->lieferant);
        self::assertSame($datum, $treffer->datum?->format('Y-m-d'));
        self::assertSame([], $treffer->hinweise);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unpassend(): iterable
    {
        yield 'scanner name' => ['Scan0001.pdf'];
        yield 'date only' => ['03.02.2026.pdf'];
        yield 'supplier only' => ['Bauhaus.pdf'];
        yield 'two digit year' => ['Bauhaus 03.02.26.pdf'];
        yield 'iso date' => ['Bauhaus 2026-02-03.pdf'];
        yield 'empty' => [''];
    }

    #[DataProvider('unpassend')]
    public function testANameThatDoesNotFitGivesNothing(string $datei): void
    {
        self::assertNull(DateinamenMuster::standard()->lies($datei, self::BIS_JAHR));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function ungueltigeDaten(): iterable
    {
        yield 'february 31st' => ['Bauhaus 31.02.2026.pdf'];
        yield 'month 13' => ['Bauhaus 01.13.2026.pdf'];
        yield 'day zero' => ['Bauhaus 00.01.2026.pdf'];
        yield 'too old' => ['Bauhaus 01.01.1899.pdf'];
        yield 'too far ahead' => ['Bauhaus 01.01.2031.pdf'];
    }

    #[DataProvider('ungueltigeDaten')]
    public function testAnInvalidDateIsNotPrefilledButTheSupplierIs(string $datei): void
    {
        $treffer = DateinamenMuster::standard()->lies($datei, self::BIS_JAHR);

        self::assertNotNull($treffer);
        self::assertNull($treffer->datum);
        self::assertSame('Bauhaus', $treffer->lieferant);
        self::assertCount(1, $treffer->hinweise);
        self::assertStringContainsString('ungültig', $treffer->hinweise[0]);
    }

    public function testTheLeapDayIsValidInALeapYearOnly(): void
    {
        self::assertSame('2028-02-29', DateinamenMuster::standard()->lies('Bauhaus 29.02.2028.pdf', 2030)?->datum?->format('Y-m-d'));
        self::assertNull(DateinamenMuster::standard()->lies('Bauhaus 29.02.2027.pdf', 2030)?->datum);
    }

    public function testTheExportsNameForAReceiptWithoutSupplierMeansNoSupplier(): void
    {
        $treffer = DateinamenMuster::standard()->lies(PfadMuster::OHNE_LIEFERANT . ' 03.02.2026.pdf', self::BIS_JAHR);

        self::assertNotNull($treffer);
        self::assertNull($treffer->lieferant);
        self::assertTrue($treffer->ohneLieferant);
        self::assertSame('2026-02-03', $treffer->datum?->format('Y-m-d'));
    }

    public function testACustomPatternWithIsoDateAndNumber(): void
    {
        $muster = DateinamenMuster::parse('{datum_iso} {lieferant} - {nr}');
        $treffer = $muster->lies('2026-03-01 Musterbau GmbH - RE-4711 (2).pdf', self::BIS_JAHR);

        self::assertNotNull($treffer);
        self::assertSame('2026-03-01', $treffer->datum?->format('Y-m-d'));
        self::assertSame('Musterbau GmbH', $treffer->lieferant);
        self::assertSame('RE-4711', $treffer->nr);
    }

    public function testIgnoredTextAndAWrittenOutExtension(): void
    {
        $muster = DateinamenMuster::parse('{egal} {lieferant} {datum}.pdf');
        $treffer = $muster->lies('Rechnung Bauhaus 03.02.2026.pdf', self::BIS_JAHR);

        self::assertSame('{egal} {lieferant} {datum}', $muster->muster);
        // {egal} is lazy: it takes as little as it can.
        self::assertSame('Bauhaus', $treffer?->lieferant);
    }

    public function testLiteralTextMustAppear(): void
    {
        $muster = DateinamenMuster::parse('RE {nr} {lieferant}');

        self::assertSame('17', $muster->lies('RE 17 Bauhaus.pdf', self::BIS_JAHR)?->nr);
        self::assertNull($muster->lies('Bauhaus 17.pdf', self::BIS_JAHR));
    }

    public function testRegexCharactersInTheLiteralTextAreLiteral(): void
    {
        $muster = DateinamenMuster::parse('{lieferant} (+) {datum}');

        self::assertSame('Bauhaus', $muster->lies('Bauhaus (+) 03.02.2026.pdf', self::BIS_JAHR)?->lieferant);
        self::assertNull($muster->lies('Bauhaus + 03.02.2026.pdf', self::BIS_JAHR));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function ungueltigeMuster(): iterable
    {
        yield 'empty' => ['  ', 'Bitte ein Muster angeben.'];
        yield 'no placeholder' => ['Rechnung', 'mindestens einen Platzhalter'];
        yield 'only ignored text' => ['{egal}', 'mindestens einen Platzhalter'];
        yield 'unknown placeholder' => ['{lieferant} {betrag}', 'Unbekannter Platzhalter'];
        yield 'twice' => ['{lieferant} {lieferant}', 'nur einmal'];
        yield 'adjacent' => ['{lieferant}{datum}', 'Trennzeichen'];
        yield 'both dates' => ['{datum} {datum_iso}', 'nicht beide'];
        yield 'folder' => ['{jahr}/{lieferant} {datum}', 'ohne „/“'];
        yield 'stray brace' => ['{lieferant} {datum', 'Geschweifte Klammern'];
        yield 'too long' => [str_repeat('x', 300) . '{lieferant}', 'zu lang'];
    }

    #[DataProvider('ungueltigeMuster')]
    public function testUnusablePatternsAreRefused(string $muster, string $meldung): void
    {
        $this->expectException(UngueltigesDateinamenMuster::class);
        $this->expectExceptionMessage($meldung);

        DateinamenMuster::parse($muster);
    }
}
