<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Domain\DocumentStatus;
use App\Service\Export\ExportFilter;
use App\Service\Export\ExportStatus;
use PHPUnit\Framework\TestCase;

/**
 * The filters of the ZIP export (issue #76/M12-2, docs/spec/
 * 05-auswertung-und-export.md section 2).
 */
final class ExportFilterTest extends TestCase
{
    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        $this->heute = new \DateTimeImmutable('2026-10-08 15:30:00');
    }

    public function testDefaultsToTheBusinessYearAndCheckedReceipts(): void
    {
        $filter = ExportFilter::ausFeldern([], $this->heute);

        self::assertSame('2026-01-01 00:00:00', $filter->von->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 00:00:00', $filter->bis->format('Y-m-d H:i:s'));
        self::assertSame(ExportStatus::Geprueft, $filter->status);
        self::assertNull($filter->kategorieId);
        self::assertNull($filter->kostenstelleId);
        self::assertNull($filter->lieferantId);
        self::assertFalse($filter->originale);
        self::assertNull($filter->fehler());
    }

    public function testReadsEveryField(): void
    {
        $filter = ExportFilter::ausFeldern([
            'von' => '2026-01-01',
            'bis' => '2026-03-31',
            'status' => 'alle',
            'kategorie' => '7',
            'kostenstelle' => ExportFilter::OHNE,
            'lieferant' => ' 12 ',
            'originale' => '1',
        ], $this->heute);

        self::assertSame('2026-01-01', $filter->von->format('Y-m-d'));
        self::assertSame('2026-03-31', $filter->bis->format('Y-m-d'));
        self::assertSame(ExportStatus::Alle, $filter->status);
        self::assertSame(7, $filter->kategorieId);
        self::assertSame(0, $filter->kostenstelleId, '"ohne" means none assigned');
        self::assertSame(12, $filter->lieferantId);
        self::assertTrue($filter->originale);
    }

    public function testMalformedValuesFallBackToTheDefault(): void
    {
        $filter = ExportFilter::ausFeldern([
            'von' => '2026-02-30',
            'bis' => '31.12.2026',
            'status' => 'abgelehnt',
            'kategorie' => '0',
            'kostenstelle' => '-3',
            'lieferant' => '1 OR 1=1',
            'originale' => 'ja',
        ], $this->heute);

        self::assertEquals(ExportFilter::ausFeldern([], $this->heute), $filter);
    }

    public function testFromAfterToIsAnError(): void
    {
        $filter = ExportFilter::ausFeldern(['von' => '2026-06-01', 'bis' => '2026-05-31'], $this->heute);

        self::assertSame('Das Von-Datum liegt nach dem Bis-Datum.', $filter->fehler());
        self::assertNull(ExportFilter::ausFeldern(['von' => '2026-06-01', 'bis' => '2026-06-01'], $this->heute)->fehler(), 'one day is fine');
    }

    public function testRoundTripsThroughTheFormFields(): void
    {
        foreach ([
            ExportFilter::ausFeldern([], $this->heute),
            new ExportFilter(new \DateTimeImmutable('2025-01-01 00:00:00'), new \DateTimeImmutable('2025-12-31 00:00:00'), ExportStatus::Festgeschrieben, 0, 3, 0, true),
            new ExportFilter(new \DateTimeImmutable('2026-04-01 00:00:00'), new \DateTimeImmutable('2026-06-30 00:00:00'), ExportStatus::Alle, 4, null, 9),
        ] as $filter) {
            self::assertEquals($filter, ExportFilter::ausFeldern($filter->alsFelder(), $this->heute));
        }
    }

    public function testTheAuditDetailsHoldIdsAndDatesOnly(): void
    {
        $filter = new ExportFilter(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), ExportStatus::Alle, 4, 0, 9, true);

        self::assertSame([
            'von' => '2026-01-01',
            'bis' => '2026-12-31',
            'status' => 'alle',
            'kategorie' => 4,
            'kostenstelle' => 0,
            'lieferant' => 9,
            'originale' => true,
        ], $filter->auditDetails());
    }

    public function testTheStatusChoices(): void
    {
        self::assertSame([DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], ExportStatus::Geprueft->statusse());
        self::assertSame([DocumentStatus::Festgeschrieben], ExportStatus::Festgeschrieben->statusse());

        $alle = ExportStatus::Alle->statusse();
        self::assertNotContains(DocumentStatus::Abgelehnt, $alle, 'a rejected receipt is never exported');
        self::assertCount(count(DocumentStatus::cases()) - 1, $alle);

        foreach (DocumentStatus::cases() as $status) {
            self::assertSame(
                !in_array($status, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], true),
                ExportStatus::ungeprueft($status),
                $status->value,
            );
        }
    }
}
