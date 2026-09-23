<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbox;

use App\Service\Inbox\InboxAnsicht;
use App\Service\Inbox\InboxFilter;
use PHPUnit\Framework\TestCase;

/**
 * The filters of /app/posteingang read from the query string (issue
 * #27/M4-5): what does not parse is dropped, never an error.
 */
final class InboxFilterTest extends TestCase
{
    public function testWithoutParametersTheOpenViewIsShown(): void
    {
        $filter = InboxFilter::fromQuery([]);

        self::assertSame(InboxAnsicht::Offen, $filter->ansicht);
        self::assertNull($filter->von);
        self::assertNull($filter->bis);
        self::assertNull($filter->kostenstelle);
        self::assertSame('', $filter->suche);
        self::assertSame([], $filter->toQuery());
        self::assertFalse($filter->eingeschraenkt());
    }

    public function testValidValuesAreTakenAndRoundTrip(): void
    {
        $query = ['ansicht' => 'abgelehnt', 'von' => '2026-01-01', 'bis' => '2026-01-31', 'kostenstelle' => '4', 'suche' => 'Trikots'];
        $filter = InboxFilter::fromQuery($query);

        self::assertSame(InboxAnsicht::Abgelehnt, $filter->ansicht);
        self::assertSame('2026-01-01', $filter->von?->format('Y-m-d'));
        self::assertSame('2026-01-31', $filter->bis?->format('Y-m-d'));
        self::assertSame(4, $filter->kostenstelle);
        self::assertSame('Trikots', $filter->suche);
        self::assertSame($query, $filter->toQuery());
        self::assertTrue($filter->eingeschraenkt());
    }

    public function testWithoutCostCenterIsItsOwnValue(): void
    {
        $filter = InboxFilter::fromQuery(['kostenstelle' => 'ohne']);

        self::assertSame(0, $filter->kostenstelle);
        self::assertSame(['kostenstelle' => 'ohne'], $filter->toQuery());
    }

    public function testGarbageIsDropped(): void
    {
        $filter = InboxFilter::fromQuery([
            'ansicht' => 'spam',
            'von' => '2026-02-30',
            'bis' => ['2026-01-01'],
            'kostenstelle' => '-3',
            'suche' => str_repeat('x', 500),
        ]);

        self::assertSame(InboxAnsicht::Offen, $filter->ansicht);
        self::assertNull($filter->von);
        self::assertNull($filter->bis);
        self::assertNull($filter->kostenstelle);
        self::assertSame(100, mb_strlen($filter->suche));
    }
}
