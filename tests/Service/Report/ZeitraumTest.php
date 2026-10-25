<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Domain\Zugriffsbereich;
use App\Service\Report\Zeitraum;
use PHPUnit\Framework\TestCase;

/** App\Service\Report\Zeitraum (issue #71, E-16: business year = calendar year). */
final class ZeitraumTest extends TestCase
{
    public function testYearQuarterAndMonthCoverWholeDays(): void
    {
        self::assertSame(['2026-01-01', '2026-12-31'], self::tage(Zeitraum::jahr(2026)));
        self::assertSame(['2026-04-01', '2026-06-30'], self::tage(Zeitraum::quartal(2026, 2)));
        self::assertSame(['2026-10-01', '2026-12-31'], self::tage(Zeitraum::quartal(2026, 4)));
        self::assertSame(['2024-02-01', '2024-02-29'], self::tage(Zeitraum::monat(2024, 2)));
        self::assertSame(['2026-03-05', '2026-03-09'], self::tage(Zeitraum::frei(new \DateTimeImmutable('2026-03-05 17:30'), new \DateTimeImmutable('2026-03-09 08:00'))));
    }

    public function testThePreviousYearKeepsMonthEnds(): void
    {
        self::assertSame(['2025-01-01', '2025-12-31'], self::tage(Zeitraum::jahr(2026)->vorjahr()));
        self::assertSame(['2024-02-01', '2024-02-29'], self::tage(Zeitraum::monat(2025, 2)->vorjahr()));
        self::assertSame(['2023-02-01', '2023-02-28'], self::tage(Zeitraum::monat(2024, 2)->vorjahr()));
        self::assertSame(['2023-02-28', '2023-03-10'], self::tage(Zeitraum::frei(new \DateTimeImmutable('2024-02-29'), new \DateTimeImmutable('2024-03-10'))->vorjahr()));
    }

    public function testContainsComparesDaysOnly(): void
    {
        $q = Zeitraum::quartal(2026, 1);
        self::assertTrue($q->enthaelt(new \DateTimeImmutable('2026-03-31 23:59:59')));
        self::assertTrue($q->enthaelt(new \DateTimeImmutable('2026-01-01 00:00')));
        self::assertFalse($q->enthaelt(new \DateTimeImmutable('2025-12-31 23:59')));
        self::assertFalse($q->enthaelt(new \DateTimeImmutable('2026-04-01')));
    }

    public function testMonthsListEveryTouchedMonth(): void
    {
        self::assertSame(['2025-11', '2025-12', '2026-01'], Zeitraum::frei(new \DateTimeImmutable('2025-11-30'), new \DateTimeImmutable('2026-01-01'))->monate());
        self::assertCount(12, Zeitraum::jahr(2026)->monate());
    }

    public function testThePeriodScopeNarrowsThePeriod(): void
    {
        $jahr = Zeitraum::jahr(2026);
        self::assertSame(['2026-01-01', '2026-12-31'], self::tage($jahr->begrenztAuf(Zugriffsbereich::unbeschraenkt())));
        self::assertSame(['2026-03-01', '2026-06-30'], self::tage($jahr->begrenztAuf(new Zugriffsbereich(null, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-06-30')))));
        self::assertSame(['2026-01-01', '2026-02-15'], self::tage($jahr->begrenztAuf(new Zugriffsbereich(null, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-02-15')))));
        self::assertNull($jahr->begrenztAuf(new Zugriffsbereich(null, new \DateTimeImmutable('2027-01-01'))));
        self::assertNull($jahr->begrenztAuf(new Zugriffsbereich(null, null, new \DateTimeImmutable('2025-12-31'))));
    }

    public function testInvalidPeriodsAreRejected(): void
    {
        foreach ([
            static fn() => Zeitraum::quartal(2026, 5),
            static fn() => Zeitraum::monat(2026, 0),
            static fn() => Zeitraum::frei(new \DateTimeImmutable('2026-02-02'), new \DateTimeImmutable('2026-02-01')),
        ] as $fall) {
            try {
                $fall();
                self::fail('expected an exception');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private static function tage(?Zeitraum $z): array
    {
        self::assertNotNull($z);

        return [$z->von->format('Y-m-d'), $z->bis->format('Y-m-d')];
    }
}
