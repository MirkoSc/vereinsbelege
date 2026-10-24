<?php

declare(strict_types=1);

namespace App\Tests\Service\Invoice;

use App\Domain\InvoiceDirection;
use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;
use App\Service\Invoice\SerienAnsicht;
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

    public function testOnlyTheReceiptsTheFiguresNeedAreDecrypted(): void
    {
        $belege = [];
        foreach (range(1, 36) as $i) {
            $belege[] = [(new \DateTimeImmutable('2023-01-01'))->modify('+' . ($i - 1) . ' months')->format('Y-m-d'), 4500, $i];
        }
        $gelesen = [];
        $k = self::berechne(SeriesPeriod::Monat, 4500, $belege, '2026-01-02', protokoll: $gelesen);

        self::assertSame(36, $k->letzter?->invoiceId);
        self::assertSame(24, $k->trendBasis?->invoiceId, 'twelve months before');
        self::assertSame([36, 24], $gelesen);
    }

    public function testAReceiptThatCannotBeReadIsSkipped(): void
    {
        $k = self::berechne(SeriesPeriod::Monat, 4500, [['2026-01-01', 4000, 1], ['2026-02-01', 4500, 2], ['2026-03-01', 4500, 3]], '2026-03-02', unlesbar: [3, 1]);

        self::assertSame(2, $k->letzter?->invoiceId);
        self::assertNull($k->trendPromille, 'no other readable receipt');
    }

    public function testACreditNoteAsNewestReceiptHasNoTrend(): void
    {
        $k = self::berechne(SeriesPeriod::Monat, 4500, [['2026-01-01', 4500, 1], ['2026-02-01', 4500, 2], ['2026-03-01', -5000, 3]], '2026-03-02');

        self::assertSame(3, $k->letzter?->invoiceId);
        self::assertNull($k->trendText());
    }

    public function testTheSumsCountConfirmedSeriesWithARhythm(): void
    {
        $ansicht = static function (SeriesPeriod $periode, int $cent, SeriesStatus $status, InvoiceDirection $richtung = InvoiceDirection::Ausgabe, string $waehrung = 'EUR'): SerienAnsicht {
            $record = self::record($periode, $status, null, 0, $richtung);
            $daten = new SeriesData('', $cent, $waehrung, '');

            return new SerienAnsicht($record, $daten, 'x', 0, kennzahlen: SerienKennzahlen::berechne($record, $daten, [], static fn(int $id): ?SerienPosten => null, new \DateTimeImmutable()));
        };

        $summen = SerienKennzahlen::summen([
            $ansicht(SeriesPeriod::Monat, 45000, SeriesStatus::Bestaetigt),
            $ansicht(SeriesPeriod::Jahr, 12000, SeriesStatus::Bestaetigt),
            $ansicht(SeriesPeriod::Unregelmaessig, 99900, SeriesStatus::Bestaetigt),
            $ansicht(SeriesPeriod::Monat, 99900, SeriesStatus::Vorgeschlagen),
            $ansicht(SeriesPeriod::Quartal, 5000, SeriesStatus::Bestaetigt, InvoiceDirection::Einnahme),
            $ansicht(SeriesPeriod::Jahr, 3000, SeriesStatus::Bestaetigt, waehrung: 'CHF'),
        ]);

        self::assertSame([
            ['richtung' => InvoiceDirection::Ausgabe, 'waehrung' => 'CHF', 'cent' => 3000, 'anzahl' => 1],
            ['richtung' => InvoiceDirection::Ausgabe, 'waehrung' => 'EUR', 'cent' => 552000, 'anzahl' => 2],
            ['richtung' => InvoiceDirection::Einnahme, 'waehrung' => 'EUR', 'cent' => 20000, 'anzahl' => 1],
        ], $summen);
    }

    /**
     * @param list<array{0: string, 1: int, 2: int, 3?: string}> $belege date, gross cents, invoice id, currency
     * @param list<int> $unlesbar invoice ids that cannot be decrypted
     * @param list<int> $protokoll the ids decrypted, in order
     */
    private static function berechne(
        SeriesPeriod $periode,
        int $erwartet,
        array $belege,
        string $heute,
        ?string $naechste = '2026-09-01',
        int $toleranz = 7,
        SeriesStatus $status = SeriesStatus::Bestaetigt,
        array $unlesbar = [],
        array &$protokoll = [],
    ): SerienKennzahlen {
        $daten = [];
        $posten = [];
        foreach ($belege as $b) {
            $daten[$b[2]] = new \DateTimeImmutable($b[0]);
            $posten[$b[2]] = new SerienPosten($b[2], 100 + $b[2], $daten[$b[2]], $b[1], $b[3] ?? 'EUR', true);
        }
        $entschluessle = static function (int $id) use ($posten, $unlesbar, &$protokoll): ?SerienPosten {
            $protokoll[] = $id;

            return in_array($id, $unlesbar, true) ? null : $posten[$id];
        };

        return SerienKennzahlen::berechne(
            self::record($periode, $status, $naechste, $toleranz),
            new SeriesData('', $erwartet, 'EUR', ''),
            $daten,
            $entschluessle,
            new \DateTimeImmutable($heute),
        );
    }

    private static function record(SeriesPeriod $periode, SeriesStatus $status, ?string $naechste, int $toleranz, InvoiceDirection $richtung = InvoiceDirection::Ausgabe): RecurringSeriesRecord
    {
        $jetzt = new \DateTimeImmutable('2026-01-01');

        return new RecurringSeriesRecord(
            1, 1, $richtung, $periode, $status,
            $naechste === null ? null : new \DateTimeImmutable($naechste), $toleranz,
            '', '', null, null, $jetzt, $jetzt,
        );
    }
}
