<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Service\Report\Zeitraumart;
use App\Service\Report\Zeitraumwahl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period filter of the report pages (issue #73): year, quarter, month
 * and free range from the query string, back into parameters and a label;
 * anything invalid falls back to the default year and says so.
 */
final class ZeitraumwahlTest extends TestCase
{
    public function testWithoutAChoiceTheDefaultYear(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage([], 2025, 2000, 2026);

        self::assertSame(Zeitraumart::Jahr, $wahl->art);
        self::assertSame(2025, $wahl->jahr);
        self::assertFalse($wahl->korrigiert);
        self::assertSame('2025-01-01', $wahl->zeitraum()->von->format('Y-m-d'));
        self::assertSame('2025-12-31', $wahl->zeitraum()->bis->format('Y-m-d'));
        self::assertSame(['zeitraum' => 'jahr', 'jahr' => '2025'], $wahl->parameter());
        self::assertSame('2025', $wahl->bezeichnung());
    }

    public function testAYearAloneIsTakenAsAYear(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage(['jahr' => '2023'], 2025, 2000, 2026);

        self::assertSame(Zeitraumart::Jahr, $wahl->art);
        self::assertSame(2023, $wahl->jahr);
        self::assertFalse($wahl->korrigiert);
    }

    public function testQuarter(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage(['zeitraum' => 'quartal', 'jahr' => '2024', 'quartal' => '2', 'monat' => '11'], 2025, 2000, 2026);

        self::assertSame('2024-04-01', $wahl->zeitraum()->von->format('Y-m-d'));
        self::assertSame('2024-06-30', $wahl->zeitraum()->bis->format('Y-m-d'));
        // Only what the kind needs goes back into a link.
        self::assertSame(['zeitraum' => 'quartal', 'jahr' => '2024', 'quartal' => '2'], $wahl->parameter());
        self::assertSame('2. Quartal 2024', $wahl->bezeichnung());
    }

    public function testMonth(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage(['zeitraum' => 'monat', 'jahr' => '2024', 'monat' => '2'], 2025, 2000, 2026);

        self::assertSame('2024-02-01', $wahl->zeitraum()->von->format('Y-m-d'));
        self::assertSame('2024-02-29', $wahl->zeitraum()->bis->format('Y-m-d'));
        self::assertSame(['zeitraum' => 'monat', 'jahr' => '2024', 'monat' => '2'], $wahl->parameter());
        self::assertSame('Februar 2024', $wahl->bezeichnung());
    }

    public function testFreeRange(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage(['zeitraum' => 'frei', 'jahr' => '1990', 'von' => '2024-11-15', 'bis' => '2025-02-10'], 2025, 2000, 2026);

        self::assertFalse($wahl->korrigiert);
        self::assertSame('2024-11-15', $wahl->zeitraum()->von->format('Y-m-d'));
        self::assertSame('2025-02-10', $wahl->zeitraum()->bis->format('Y-m-d'));
        self::assertSame(['zeitraum' => 'frei', 'von' => '2024-11-15', 'bis' => '2025-02-10'], $wahl->parameter());
        self::assertSame('15.11.2024 – 10.02.2025', $wahl->bezeichnung());
    }

    public function testAFreeRangeOfJustUnderThreeYears(): void
    {
        $wahl = Zeitraumwahl::ausAnfrage(['zeitraum' => 'frei', 'von' => '2020-01-01', 'bis' => '2022-12-31'], 2025, 2000, 2026);

        self::assertFalse($wahl->korrigiert);
        self::assertSame(Zeitraumart::Frei, $wahl->art);
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('ungueltig')]
    public function testAnInvalidChoiceFallsBackToTheDefaultYear(array $query): void
    {
        $wahl = Zeitraumwahl::ausAnfrage($query, 2025, 2000, 2026);

        self::assertTrue($wahl->korrigiert);
        self::assertSame(Zeitraumart::Jahr, $wahl->art);
        self::assertSame(2025, $wahl->jahr);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ungueltig(): iterable
    {
        yield 'unknown kind' => [['zeitraum' => 'woche', 'jahr' => '2024']];
        yield 'kind as array' => [['zeitraum' => ['jahr']]];
        yield 'year before the first' => [['zeitraum' => 'jahr', 'jahr' => '1999']];
        yield 'future year' => [['zeitraum' => 'jahr', 'jahr' => '2027']];
        yield 'year not a number' => [['zeitraum' => 'jahr', 'jahr' => '2024; DROP']];
        yield 'year with sign' => [['jahr' => '+2024']];
        yield 'year missing' => [['zeitraum' => 'quartal', 'quartal' => '1']];
        yield 'quarter 5' => [['zeitraum' => 'quartal', 'jahr' => '2024', 'quartal' => '5']];
        yield 'quarter missing' => [['zeitraum' => 'quartal', 'jahr' => '2024']];
        yield 'month 13' => [['zeitraum' => 'monat', 'jahr' => '2024', 'monat' => '13']];
        yield 'month 0' => [['zeitraum' => 'monat', 'jahr' => '2024', 'monat' => '0']];
        yield 'free without end' => [['zeitraum' => 'frei', 'von' => '2024-01-01']];
        yield 'free end before start' => [['zeitraum' => 'frei', 'von' => '2024-03-01', 'bis' => '2024-02-01']];
        yield 'free impossible date' => [['zeitraum' => 'frei', 'von' => '2024-02-30', 'bis' => '2024-03-01']];
        yield 'free other format' => [['zeitraum' => 'frei', 'von' => '01.02.2024', 'bis' => '2024-03-01']];
        yield 'free longer than three years' => [['zeitraum' => 'frei', 'von' => '2020-01-01', 'bis' => '2023-01-01']];
        yield 'free beyond the last year' => [['zeitraum' => 'frei', 'von' => '2026-12-01', 'bis' => '2027-01-31']];
    }
}
