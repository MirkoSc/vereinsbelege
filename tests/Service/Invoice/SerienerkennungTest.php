<?php

declare(strict_types=1);

namespace App\Tests\Service\Invoice;

use App\Domain\SeriesPeriod;
use App\Service\Invoice\SerienBeleg;
use App\Service\Invoice\Serienerkennung;
use PHPUnit\Framework\TestCase;

/**
 * Series detection (issue #57/M8-1, docs/spec/03-erfassung-und-ki.md
 * section 9 "Pflicht-Tests": monthly, quarterly, yearly, irregular → no
 * series, price increase within the tolerance). Pure, no database.
 */
final class SerienerkennungTest extends TestCase
{
    public function testMonthlyRentIsASeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2026-01-03', 45000], ['2026-02-01', 45000], ['2026-03-02', 45000], ['2026-04-01', 45000],
        ]));

        self::assertNotNull($kandidat);
        self::assertSame(SeriesPeriod::Monat, $kandidat->periode);
        self::assertSame([1, 2, 3, 4], $kandidat->invoiceIds);
        self::assertSame(45000, $kandidat->letzterBetrag);
        self::assertSame('2026-05-01', $kandidat->naechsteFaelligkeit->format('Y-m-d'));
    }

    public function testQuarterlyIsASeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2025-01-15', 12000], ['2025-04-14', 12000], ['2025-07-15', 12000], ['2025-10-16', 12000],
        ]));

        self::assertSame(SeriesPeriod::Quartal, $kandidat?->periode);
        self::assertSame('2026-01-16', $kandidat->naechsteFaelligkeit->format('Y-m-d'));
    }

    public function testHalfYearlyIsASeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2024-06-30', 9900], ['2024-12-30', 9900], ['2025-06-29', 9900],
        ]));

        self::assertSame(SeriesPeriod::Halbjahr, $kandidat?->periode);
    }

    public function testYearlyInsuranceIsASeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2023-01-10', 31950], ['2024-01-12', 31950], ['2025-01-09', 31950],
        ]));

        self::assertSame(SeriesPeriod::Jahr, $kandidat?->periode);
        self::assertSame('2026-01-09', $kandidat->naechsteFaelligkeit->format('Y-m-d'));
    }

    public function testIrregularReceiptsAreNoSeries(): void
    {
        // Median gap 28.5 days - but 11 and 5 days apart is not monthly.
        self::assertNull(new Serienerkennung()->erkenne(self::belege([
            ['2026-01-05', 5000], ['2026-02-20', 5000], ['2026-03-03', 5000], ['2026-05-28', 5000], ['2026-06-02', 5000],
        ])));
        // Regular amounts, but gaps of 40, 75 and 20 days fit no rhythm.
        self::assertNull(new Serienerkennung()->erkenne(self::belege([
            ['2026-01-01', 5000], ['2026-02-10', 5000], ['2026-04-26', 5000], ['2026-05-16', 5000],
        ])));
    }

    public function testPriceIncreaseWithinTheToleranceStaysInTheSeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2026-01-01', 5000], ['2026-02-01', 5000], ['2026-03-01', 5500], ['2026-04-01', 5500], ['2026-05-01', 6000],
        ]));

        self::assertSame([1, 2, 3, 4, 5], $kandidat?->invoiceIds);
        self::assertSame(6000, $kandidat->letzterBetrag, 'the newest amount is the expected one');
    }

    public function testAJumpBeyondTheToleranceEndsTheSeries(): void
    {
        $belege = [['2026-01-01', 5000], ['2026-02-01', 5000], ['2026-03-01', 5000], ['2026-04-01', 8000], ['2026-05-01', 8000]];

        // From the newest: 80,00 twice is too few, so the older 50,00 series counts.
        self::assertSame([1, 2, 3], new Serienerkennung()->erkenne(self::belege($belege))?->invoiceIds);
        // A wider tolerance takes the jump.
        self::assertSame([1, 2, 3, 4, 5], new Serienerkennung(70)->erkenne(self::belege($belege))?->invoiceIds);
    }

    public function testAOneOffPurchaseAmongTheSeriesIsSkipped(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2026-01-01', 45000], ['2026-02-01', 45000], ['2026-02-14', 1299], ['2026-03-01', 45000],
        ]));

        self::assertSame([1, 2, 4], $kandidat?->invoiceIds);
        self::assertSame(SeriesPeriod::Monat, $kandidat->periode);
    }

    public function testTheNewestReceiptBeingAOneOffStillFindsTheSeries(): void
    {
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2026-01-01', 45000], ['2026-02-01', 45000], ['2026-03-01', 45000], ['2026-03-20', 99000],
        ]));

        self::assertSame([1, 2, 3], $kandidat?->invoiceIds);
    }

    public function testTwoReceiptsNeedTheAiHint(): void
    {
        $belege = self::belege([['2025-03-01', 20000], ['2026-03-02', 20000]]);
        $erkennung = new Serienerkennung();

        self::assertNull($erkennung->erkenne($belege));
        self::assertSame(SeriesPeriod::Jahr, $erkennung->erkenne($belege, true)?->periode);
        self::assertSame(SeriesPeriod::Jahr, $erkennung->erkenne($belege, true, SeriesPeriod::Jahr)?->periode);
        self::assertNull($erkennung->erkenne($belege, true, SeriesPeriod::Monat), 'the AI named another rhythm');
        self::assertNull($erkennung->erkenne(self::belege([['2026-03-01', 20000]]), true), 'one receipt is never a series');
    }

    public function testALongGapCutsTheSeries(): void
    {
        // Two old ones, a year without, then three new monthly ones.
        $kandidat = new Serienerkennung()->erkenne(self::belege([
            ['2024-11-01', 3000], ['2024-12-01', 3000], ['2026-01-01', 3000], ['2026-02-01', 3000], ['2026-03-01', 3000],
        ]));

        self::assertSame([3, 4, 5], $kandidat?->invoiceIds);
        // One missing month (61 days) does not cut it.
        self::assertSame([1, 2, 3, 4], new Serienerkennung()->erkenne(self::belege([
            ['2026-01-01', 3000], ['2026-02-01', 3000], ['2026-04-01', 3000], ['2026-05-01', 3000],
        ]))?->invoiceIds);
    }

    public function testOtherCurrenciesAndCreditNotesDoNotCount(): void
    {
        $belege = self::belege([['2026-01-01', 3000], ['2026-02-01', 3000], ['2026-03-01', 3000]]);
        $belege[1] = new SerienBeleg(2, $belege[1]->datum, 3000, 'CHF');

        self::assertNull(new Serienerkennung()->erkenne($belege));
        self::assertNull(new Serienerkennung()->erkenne(self::belege([['2026-01-01', -3000], ['2026-02-01', -3000], ['2026-03-01', -3000]])));
    }

    public function testOrderOfTheInputDoesNotMatter(): void
    {
        $belege = self::belege([['2026-01-01', 3000], ['2026-02-01', 3000], ['2026-03-01', 3000]]);

        self::assertSame([1, 2, 3], new Serienerkennung()->erkenne(array_reverse($belege))?->invoiceIds);
    }

    public function testNextDueDateIsClampedToTheMonthsEnd(): void
    {
        $datum = static fn(string $d): \DateTimeImmutable => new \DateTimeImmutable($d);

        self::assertSame('2026-02-28', Serienerkennung::naechsteFaelligkeit($datum('2026-01-31'), SeriesPeriod::Monat)?->format('Y-m-d'));
        self::assertSame('2028-02-29', Serienerkennung::naechsteFaelligkeit($datum('2027-11-30'), SeriesPeriod::Quartal)?->format('Y-m-d'));
        self::assertSame('2027-02-28', Serienerkennung::naechsteFaelligkeit($datum('2026-02-28'), SeriesPeriod::Jahr)?->format('Y-m-d'));
        self::assertSame('2026-12-15', Serienerkennung::naechsteFaelligkeit($datum('2026-06-15'), SeriesPeriod::Halbjahr)?->format('Y-m-d'));
        self::assertNull(Serienerkennung::naechsteFaelligkeit($datum('2026-06-15'), SeriesPeriod::Unregelmaessig));
    }

    public function testAmountTolerance(): void
    {
        $erkennung = new Serienerkennung();

        self::assertTrue($erkennung->betragPasst(11500, 10000));
        self::assertTrue($erkennung->betragPasst(8500, 10000));
        self::assertFalse($erkennung->betragPasst(11501, 10000));
        self::assertTrue(new Serienerkennung(0)->betragPasst(10000, 10000));
        self::assertFalse(new Serienerkennung(0)->betragPasst(10001, 10000));

        $this->expectException(\InvalidArgumentException::class);
        new Serienerkennung(101);
    }

    /**
     * @param list<array{0: string, 1: int}> $daten date, cents; ids from 1 in this order
     *
     * @return list<SerienBeleg>
     */
    private static function belege(array $daten): array
    {
        $belege = [];
        foreach ($daten as $i => [$datum, $brutto]) {
            $belege[] = new SerienBeleg($i + 1, new \DateTimeImmutable($datum), $brutto, 'EUR');
        }

        return $belege;
    }
}
