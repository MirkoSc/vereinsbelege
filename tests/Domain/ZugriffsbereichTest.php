<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Zugriffsbereich;
use PHPUnit\Framework\TestCase;

/**
 * The scope a repository filters by (docs/spec/01-sicherheit.md section 4,
 * issue #19/M3-6). Against real rows: tests/Integration/AccessScopeTest.php.
 */
final class ZugriffsbereichTest extends TestCase
{
    public function testUnrestrictedAllowsEverythingAndAddsNoCondition(): void
    {
        $bereich = Zugriffsbereich::unbeschraenkt();

        self::assertTrue($bereich->istUnbeschraenkt());
        self::assertTrue($bereich->erlaubt(null, new \DateTimeImmutable('1999-01-01')));
        self::assertSame(['1 = 1', []], $bereich->sqlBedingung('invoice_date', 'cost_center_id'));
    }

    public function testCostCenterScope(): void
    {
        $bereich = new Zugriffsbereich(kostenstellen: [3, 5]);
        $tag = new \DateTimeImmutable('2026-05-01');

        self::assertTrue($bereich->erlaubt(3, $tag));
        self::assertFalse($bereich->erlaubt(4, $tag));
        self::assertFalse($bereich->erlaubt(null, $tag), 'a row without a cost center is outside every cost-center scope');
        self::assertSame(['(i.cost_center_id IN (?, ?))', [3, 5]], $bereich->sqlBedingung('i.invoice_date', 'i.cost_center_id'));
    }

    public function testAnEmptyCostCenterListMatchesNothing(): void
    {
        $bereich = new Zugriffsbereich(kostenstellen: []);

        self::assertFalse($bereich->erlaubt(3, new \DateTimeImmutable()));
        self::assertSame(['(1 = 0)', []], $bereich->sqlBedingung('d', 'k'));
    }

    public function testACostCenterScopeOnATableWithoutCostCentersMatchesNothing(): void
    {
        self::assertSame(['(1 = 0)', []], new Zugriffsbereich(kostenstellen: [1])->sqlBedingung('booking_date', null));
    }

    public function testPeriodScopeIncludesBothBoundaryDays(): void
    {
        $bereich = new Zugriffsbereich(von: new \DateTimeImmutable('2026-01-01'), bis: new \DateTimeImmutable('2026-12-31'));

        self::assertFalse($bereich->erlaubt(null, new \DateTimeImmutable('2025-12-31 23:59:59')));
        self::assertTrue($bereich->erlaubt(null, new \DateTimeImmutable('2026-01-01 00:00:00')));
        self::assertTrue($bereich->erlaubt(null, new \DateTimeImmutable('2026-12-31 23:59:59')));
        self::assertFalse($bereich->erlaubt(null, new \DateTimeImmutable('2027-01-01')));
        self::assertSame(
            ['(booking_date >= ? AND booking_date < ?)', ['2026-01-01', '2027-01-01']],
            $bereich->sqlBedingung('booking_date', null),
        );
    }

    public function testOpenEndedPeriods(): void
    {
        self::assertSame(['(d >= ?)', ['2026-03-01']], new Zugriffsbereich(von: new \DateTimeImmutable('2026-03-01'))->sqlBedingung('d', null));
        self::assertSame(['(d < ?)', ['2026-04-01']], new Zugriffsbereich(bis: new \DateTimeImmutable('2026-03-31'))->sqlBedingung('d', null));
    }

    public function testBothScopesTogether(): void
    {
        $bereich = new Zugriffsbereich([7], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-06-30'));

        self::assertSame(
            ['(k IN (?) AND d >= ? AND d < ?)', [7, '2026-01-01', '2026-07-01']],
            $bereich->sqlBedingung('d', 'k'),
        );
        self::assertTrue($bereich->erlaubt(7, new \DateTimeImmutable('2026-03-01')));
        self::assertFalse($bereich->erlaubt(7, new \DateTimeImmutable('2026-07-01')));
        self::assertFalse($bereich->erlaubt(8, new \DateTimeImmutable('2026-03-01')));
    }

    /** Column names come from code, but a mistake must not become SQL. */
    public function testColumnNamesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Zugriffsbereich::unbeschraenkt()->sqlBedingung('d; DROP TABLE x', null);
    }
}
