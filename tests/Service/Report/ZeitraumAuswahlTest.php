<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Service\Report\ZeitraumAuswahl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period filter of the report pages (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1: year/quarter/month/free).
 */
final class ZeitraumAuswahlTest extends TestCase
{
    private const string HEUTE = '2026-10-25';

    /**
     * @return iterable<string, array{array<string, string>, string, string, string}>
     */
    public static function gueltig(): iterable
    {
        yield 'year' => [['art' => 'jahr', 'jahr' => '2025'], 'jahr', '2025-01-01', '2025-12-31'];
        yield 'quarter' => [['art' => 'quartal', 'jahr' => '2024', 'quartal' => '1'], 'quartal', '2024-01-01', '2024-03-31'];
        yield 'month (leap year)' => [['art' => 'monat', 'jahr' => '2024', 'monat' => '2'], 'monat', '2024-02-01', '2024-02-29'];
        yield 'free' => [['art' => 'frei', 'von' => '2025-03-15', 'bis' => '2025-04-14'], 'frei', '2025-03-15', '2025-04-14'];
        yield 'free = a calendar year' => [['art' => 'frei', 'von' => '2023-01-01', 'bis' => '2023-12-31'], 'jahr', '2023-01-01', '2023-12-31'];
        yield 'next year' => [['art' => 'jahr', 'jahr' => '2027'], 'jahr', '2027-01-01', '2027-12-31'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('gueltig')]
    public function testAWellFormedQueryGivesItsPeriodAndComesBackTheSame(array $query, string $art, string $von, string $bis): void
    {
        $auswahl = ZeitraumAuswahl::ausAnfrage($query, ZeitraumAuswahl::jahr(2000), new \DateTimeImmutable(self::HEUTE));

        self::assertSame($art, $auswahl->art);
        self::assertSame($von, $auswahl->zeitraum->von->format('Y-m-d'));
        self::assertSame($bis, $auswahl->zeitraum->bis->format('Y-m-d'));

        $wieder = ZeitraumAuswahl::ausAnfrage($auswahl->query(), ZeitraumAuswahl::jahr(2000), new \DateTimeImmutable(self::HEUTE));
        self::assertEquals($auswahl, $wieder);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function ungueltig(): iterable
    {
        yield 'nothing' => [[]];
        yield 'unknown kind' => [['art' => 'woche', 'jahr' => '2025']];
        yield 'year too early' => [['art' => 'jahr', 'jahr' => '1999']];
        yield 'year too late' => [['art' => 'jahr', 'jahr' => '2028']];
        yield 'year not a number' => [['art' => 'jahr', 'jahr' => '20x5']];
        yield 'year with space' => [['art' => 'jahr', 'jahr' => ' 2025']];
        yield 'year as array' => [['art' => 'jahr', 'jahr' => ['2025']]];
        yield 'quarter 5' => [['art' => 'quartal', 'jahr' => '2025', 'quartal' => '5']];
        yield 'quarter without year' => [['art' => 'quartal', 'quartal' => '2']];
        yield 'month 13' => [['art' => 'monat', 'jahr' => '2025', 'monat' => '13']];
        yield 'month 0' => [['art' => 'monat', 'jahr' => '2025', 'monat' => '0']];
        yield 'impossible date' => [['art' => 'frei', 'von' => '2025-02-30', 'bis' => '2025-03-01']];
        yield 'end before start' => [['art' => 'frei', 'von' => '2025-03-01', 'bis' => '2025-02-01']];
        yield 'other date format' => [['art' => 'frei', 'von' => '01.03.2025', 'bis' => '2025-04-01']];
        yield 'free far in the past' => [['art' => 'frei', 'von' => '1970-01-01', 'bis' => '2025-04-01']];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('ungueltig')]
    public function testAnythingMalformedFallsBackToTheDefault(array $query): void
    {
        $standard = ZeitraumAuswahl::jahr(2025);

        self::assertSame($standard, ZeitraumAuswahl::ausAnfrage($query, $standard, new \DateTimeImmutable(self::HEUTE)));
    }
}
