<?php

declare(strict_types=1);

namespace App\Tests\Service\Invoice;

use App\Domain\InvoiceDirection;
use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;
use App\Service\Invoice\SerienFaelligkeit;
use App\Service\Invoice\SerienKennzahlen;
use App\Service\Invoice\SerienPosten;
use PHPUnit\Framework\TestCase;

/**
 * The figures of the overview "Wiederkehrende Kosten" (issue #58/M8-2,
 * docs/spec/03-erfassung-und-ki.md section 9 "Pflicht-Tests": yearly
 * projection, trend, "überfällig" after the tolerance). Pure, no database.
 */
final class SerienKennzahlenTest extends TestCase
{
    public function testTheNewestReceiptAndTheYearlyProjection(): void
    {
        $k = self::berechne(SeriesPeriod::Quartal, 12000, [['2026-07-15', 11000, 3], ['2026-01-15', 10000, 1], ['2026-04-14', 10500, 2]], '2026-08-01');

        self::assertSame(3, $k->letzter?->invoiceId);
        self::assertSame(48000, $k->jahreshochrechnung, 'expected amount times four');

        self::assertSame(54000, self::berechne(SeriesPeriod::Monat, 4500, [], '2026-08-01')->jahreshochrechnung);
        self::assertSame(2400, self::berechne(SeriesPeriod::Halbjahr, 1200, [], '2026-08-01')->jahreshochrechnung);
        self::assertSame(9900, self::berechne(SeriesPeriod::Jahr, 9900, [], '2026-08-01')->jahreshochrechnung);
        self::assertNull(self::berechne(SeriesPeriod::Unregelmaessig, 9900, [], '2026-08-01')->jahreshochrechnung);
        self::assertNull(self::berechne(SeriesPeriod::Monat, 4500, [], '2026-08-01')->letzter);
    }

    public function testTheTrendComparesWithAboutAYearBefore(): void
    {
        $k = self::berechne(SeriesPeriod::Monat, 47250, [
            ['2025-05-01', 40000, 1],
            ['2025-08-01', 45000, 2],
            ['2025-11-01', 45000, 3],
            ['2026-08-01', 47250, 4],
        ], '2026-08-02');

        self::assertSame(2, $k->trendBasis?->invoiceId, 'the newest one at least 335 days older');
        self::assertSame(50, $k->trendPromille);
        self::assertSame('+5,0 %', $k->trendText());
    }

    public function testWithoutAYearTheTrendStartsAtTheOldestReceipt(): void
    {
        $k = self::berechne(SeriesPeriod::Monat, 4400, [['2026-01-01', 4500, 1], ['2026-02-01', 4500, 2], ['2026-03-01', 4400, 3]], '2026-03-02');

        self::assertSame(1, $k->trendBasis?->invoiceId);
        self::assertSame(-22, $k->trendPromille, '-2.22 % rounded');
        self::assertSame('−2,2 %', $k->trendText());

        $gleich = self::berechne(SeriesPeriod::Monat, 4500, [['2026-01-01', 4500, 1], ['2026-02-01', 4500, 2]], '2026-03-02');
        self::assertSame('±0,0 %', $gleich->trendText());
    }

    public function testNoTrendFromOneReceiptOrAnotherCurrency(): void
    {
        self::assertNull(self::berechne(SeriesPeriod::Monat, 4500, [['2026-01-01', 4500, 1]], '2026-03-02')->trendText());

        $k = self::berechne(SeriesPeriod::Monat, 4500, [['2026-01-01', 9000, 1, 'CHF'], ['2026-02-01', 4500, 2]], '2026-03-02');
        self::assertNull($k->trendPromille);
        self::assertNull($k->trendBasis);
    }

    public function testOverdueOnlyAfterTheTolerance(): void
    {
        $faellig = static fn(string $heute): SerienFaelligkeit => self::berechne(SeriesPeriod::Monat, 4500, [], $heute, '2026-05-01', 7)->faelligkeit;

        self::assertSame(SerienFaelligkeit::Offen, $faellig('2026-04-30'));
        self::assertSame(SerienFaelligkeit::Faellig, $faellig('2026-05-01'));
        self::assertSame(SerienFaelligkeit::Faellig, $faellig('2026-05-08'), 'the last day of the tolerance');
        self::assertSame(SerienFaelligkeit::Ueberfaellig, $faellig('2026-05-09'));
        self::assertSame(SerienFaelligkeit::Ueberfaellig, self::berechne(SeriesPeriod::Monat, 4500, [], '2026-05-02 08:00', '2026-05-01', 0)->faelligkeit);
    }

    public function testOnlyAConfirmedSeriesWithANextDateCanBeOverdue(): void
    {
        self::assertSame(SerienFaelligkeit::Keine, self::berechne(SeriesPeriod::Monat, 4500, [], '2027-01-01', '2026-05-01', 7, SeriesStatus::Vorgeschlagen)->faelligkeit);
        self::assertSame(SerienFaelligkeit::Keine, self::berechne(SeriesPeriod::Monat, 4500, [], '2027-01-01', '2026-05-01', 7, SeriesStatus::Verworfen)->faelligkeit);
        self::assertSame(SerienFaelligkeit::Keine, self::berechne(SeriesPeriod::Unregelmaessig, 4500, [], '2027-01-01', null)->faelligkeit);
    }

    /**
     * @param list<array{0: string, 1: int, 2: int, 3?: string}> $belege date, gross cents, invoice id, currency
     */
    private static function berechne(
        SeriesPeriod $periode,
        int $erwartet,
        array $belege,
        string $heute,
        ?string $naechste = '2026-09-01',
        int $toleranz = 7,
        SeriesStatus $status = SeriesStatus::Bestaetigt,
    ): SerienKennzahlen {
        $jetzt = new \DateTimeImmutable('2026-01-01');
        $record = new RecurringSeriesRecord(
            1, 1, InvoiceDirection::Ausgabe, $periode, $status,
            $naechste === null ? null : new \DateTimeImmutable($naechste), $toleranz,
            '', '', null, null, $jetzt, $jetzt,
        );
        $posten = array_map(
            static fn(array $b): SerienPosten => new SerienPosten($b[2], 100 + $b[2], new \DateTimeImmutable($b[0]), $b[1], $b[3] ?? 'EUR', true),
            $belege,
        );

        return SerienKennzahlen::berechne($record, new SeriesData('', $erwartet, 'EUR', ''), $posten, new \DateTimeImmutable($heute));
    }
}
