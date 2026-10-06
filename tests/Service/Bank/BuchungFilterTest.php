<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Service\Bank\BuchungFilter;
use PHPUnit\Framework\TestCase;

/**
 * The query string of the booking list (M9-5, issue #63): the current year
 * without dates, no limit with emptied dates, malformed values ignored.
 */
final class BuchungFilterTest extends TestCase
{
    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        $this->heute = new \DateTimeImmutable('2026-10-06 14:30');
    }

    public function testWithoutDatesTheCurrentYearIsShown(): void
    {
        $filter = BuchungFilter::ausAbfrage([], $this->heute);

        self::assertSame('2026-01-01', $filter->von?->format('Y-m-d'));
        self::assertSame('2026-12-31', $filter->bis?->format('Y-m-d'));
        self::assertNull($filter->kontoId);
        self::assertNull($filter->kategorieId);
        self::assertFalse($filter->aktiv($this->heute));
    }

    public function testEmptiedDatesLiftTheLimit(): void
    {
        $filter = BuchungFilter::ausAbfrage(['von' => '', 'bis' => ''], $this->heute);

        self::assertNull($filter->von);
        self::assertNull($filter->bis);
        self::assertTrue($filter->aktiv($this->heute));

        $nurVon = BuchungFilter::ausAbfrage(['von' => '2025-03-01'], $this->heute);
        self::assertSame('2025-03-01', $nurVon->von?->format('Y-m-d'));
        self::assertNull($nurVon->bis);
    }

    public function testEveryFilterIsRead(): void
    {
        $filter = BuchungFilter::ausAbfrage([
            'konto' => '3', 'von' => '2026-02-01', 'bis' => '2026-02-28', 'richtung' => 'einnahme',
            'beleg' => 'nicht_noetig', 'quelle' => 'manuell', 'kategorie' => '12', 'suche' => '  Spende ',
        ], $this->heute);

        self::assertSame(3, $filter->kontoId);
        self::assertSame(BankTransactionDirection::Einnahme, $filter->richtung);
        self::assertSame(BankTransactionDocStatus::NichtNoetig, $filter->belegStatus);
        self::assertSame(BankTransactionSource::Manuell, $filter->quelle);
        self::assertSame(12, $filter->kategorieId);
        self::assertSame('Spende', $filter->suche);
        self::assertSame(0, BuchungFilter::ausAbfrage(['kategorie' => 'ohne'], $this->heute)->kategorieId, '0 = without category');
    }

    public function testMalformedValuesAreIgnored(): void
    {
        $filter = BuchungFilter::ausAbfrage([
            'konto' => '3 OR 1=1', 'von' => '2026-02-30', 'bis' => ['x'], 'richtung' => 'beides',
            'beleg' => 'egal', 'quelle' => 'bank', 'kategorie' => '-1', 'suche' => str_repeat('x', 300),
        ], $this->heute);

        self::assertNull($filter->kontoId);
        self::assertNull($filter->von);
        self::assertNull($filter->bis);
        self::assertNull($filter->richtung);
        self::assertNull($filter->belegStatus);
        self::assertNull($filter->quelle);
        self::assertNull($filter->kategorieId);
        self::assertSame(BuchungFilter::SUCHE_MAX, mb_strlen($filter->suche));
    }

    public function testTheSearchIgnoresCase(): void
    {
        $filter = new BuchungFilter(suche: 'getränke');

        self::assertTrue($filter->passtZurSuche('', 'Verkauf GETRÄNKE Heimspiel'));
        self::assertFalse($filter->passtZurSuche('Spende', 'Erika'));
        self::assertTrue(new BuchungFilter()->passtZurSuche('irgendwas'));
    }
}
