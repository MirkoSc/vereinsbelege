<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\App\BudgetController;
use App\Domain\InvoiceDirection;
use App\Domain\Zugriffsbereich;
use App\Service\Report\Auswertungsgrundlage;
use App\Service\Report\Budgetsoll;
use App\Service\Report\Budgetzeile;
use App\Service\Report\Grundlage;
use App\Service\Report\Posten;
use App\Service\Report\ReportService;
use App\Service\Report\Zeitraum;
use PHPUnit\Framework\TestCase;

/**
 * Soll against Ist of budgets (issue #92, E-58): ReportService::
 * budgetvergleich() and the integer percentages. Pure calculation on made-up
 * report lines - ids are invented, amounts integer cents.
 */
final class BudgetvergleichTest extends TestCase
{
    private const int KAT_MATERIAL = 1;
    private const int KAT_HALLE = 2;
    private const int KST_JUGEND = 7;
    private const int KST_HERREN = 8;

    public function testIstPerCategoryCostCenterAndPair(): void
    {
        $zeilen = new ReportService()->budgetvergleich($this->grundlage(), Zugriffsbereich::unbeschraenkt(), [
            new Budgetsoll(1, 2025, self::KAT_MATERIAL, null, 100000),
            new Budgetsoll(2, 2025, null, self::KST_JUGEND, 50000),
            new Budgetsoll(3, 2025, self::KAT_MATERIAL, self::KST_HERREN, 20000),
            new Budgetsoll(4, 2025, self::KAT_HALLE, null, 0),
        ]);

        self::assertSame([
            // Material: 300 Jugend + 250 Herren - 50 credit note (Herren) = 500.
            [1, 100000, 50000, 3, 50000, 500, 500],
            // Jugend: 300 Material + 400 Halle = 700, overspent.
            [2, 50000, 70000, 2, -20000, -400, 1400],
            // Material on Herren: 250 - 50 = 200, exactly used up.
            [3, 20000, 20000, 2, 0, 0, 1000],
            // A Soll of 0 has no percentages.
            [4, 0, 40000, 1, -40000, null, null],
        ], array_map(self::werte(...), $zeilen));

        self::assertFalse($zeilen[0]->ueberschritten());
        self::assertTrue($zeilen[1]->ueberschritten());
        self::assertFalse($zeilen[2]->ueberschritten());
        self::assertSame(500, $zeilen[0]->balkenPromille());
        self::assertSame(1000, $zeilen[1]->balkenPromille(), 'overspent: the full bar, the text says how much');
        self::assertSame(1000, $zeilen[3]->balkenPromille());
    }

    public function testIncomeAndLinesOutsideTheYearDoNotCount(): void
    {
        $zeilen = new ReportService()->budgetvergleich($this->grundlage(), Zugriffsbereich::unbeschraenkt(), [
            new Budgetsoll(5, 2025, null, self::KST_HERREN, 100000),
        ]);

        // Herren: 250 - 50 expenses; the 1.000 income and the 2024 line are out.
        self::assertSame([[5, 100000, 20000, 2, 80000, 800, 200]], array_map(self::werte(...), $zeilen));
    }

    public function testACostCenterScopeSeesOnlyBudgetsAndLinesOfItsCostCenters(): void
    {
        $bereich = new Zugriffsbereich([self::KST_JUGEND]);
        $zeilen = new ReportService()->budgetvergleich($this->grundlage(), $bereich, [
            new Budgetsoll(1, 2025, self::KAT_MATERIAL, null, 100000),
            new Budgetsoll(2, 2025, null, self::KST_JUGEND, 50000),
            new Budgetsoll(3, 2025, self::KAT_MATERIAL, self::KST_HERREN, 20000),
        ]);

        // The category budget has no cost center: outside the scope, like
        // the pair on Herren. Only the Jugend budget remains.
        self::assertSame([[2, 50000, 70000, 2, -20000, -400, 1400]], array_map(self::werte(...), $zeilen));
        self::assertSame([], new ReportService()->budgetvergleich($this->grundlage(), new Zugriffsbereich([]), [
            new Budgetsoll(2, 2025, null, self::KST_JUGEND, 50000),
        ]));
    }

    public function testAPeriodScopeCountsOnlyTheReleasedPartOfTheYear(): void
    {
        $bereich = new Zugriffsbereich(null, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-03-31'));
        $zeilen = new ReportService()->budgetvergleich($this->grundlage(), $bereich, [
            new Budgetsoll(1, 2025, self::KAT_MATERIAL, null, 100000),
        ]);

        // Only the 300,00 of February.
        self::assertSame([[1, 100000, 30000, 1, 70000, 700, 300]], array_map(self::werte(...), $zeilen));
    }

    public function testPercentagesRoundHalfAwayFromZeroInIntegers(): void
    {
        self::assertSame(333, ReportService::promilleVon(1, 3));
        self::assertSame(667, ReportService::promilleVon(2, 3));
        self::assertSame(-333, ReportService::promilleVon(-1, 3));
        self::assertSame(-667, ReportService::promilleVon(-2, 3));
        // Exactly half a tenth: 0,05 % → 0,1 %, -0,05 % → -0,1 %.
        self::assertSame(1, ReportService::promilleVon(1, 2000));
        self::assertSame(-1, ReportService::promilleVon(-1, 2000));
        self::assertSame(0, ReportService::promilleVon(0, 7));
        self::assertSame(12345, ReportService::promilleVon(1234500, 100000));
        // The largest budget against a large Ist stays an exact integer.
        self::assertSame(1000, ReportService::promilleVon(99_999_999_999, 99_999_999_999));

        $this->expectException(\InvalidArgumentException::class);
        ReportService::promilleVon(1, 0);
    }

    public function testPercentText(): void
    {
        self::assertSame('50,0 %', BudgetController::prozent(500));
        self::assertSame('-40,0 %', BudgetController::prozent(-400));
        self::assertSame('-0,1 %', BudgetController::prozent(-1));
        self::assertSame('140,5 %', BudgetController::prozent(1405));
        self::assertSame('–', BudgetController::prozent(null));
    }

    /**
     * 2025 expenses: Material 300 (Jugend, Feb), Material 250 (Herren, May),
     * Material credit note -50 (Herren, Jun), Halle 400 (Jugend, Aug);
     * income 1.000 (Herren); an expense of 2024.
     */
    private function grundlage(): Auswertungsgrundlage
    {
        return new Auswertungsgrundlage(Grundlage::Belege, Zeitraum::jahr(2025), [
            new Posten(new \DateTimeImmutable('2025-02-10'), InvoiceDirection::Ausgabe, 30000, self::KAT_MATERIAL, self::KST_JUGEND, belegId: 1),
            new Posten(new \DateTimeImmutable('2025-05-10'), InvoiceDirection::Ausgabe, 25000, self::KAT_MATERIAL, self::KST_HERREN, belegId: 2),
            new Posten(new \DateTimeImmutable('2025-06-10'), InvoiceDirection::Ausgabe, -5000, self::KAT_MATERIAL, self::KST_HERREN, belegId: 3),
            new Posten(new \DateTimeImmutable('2025-08-10'), InvoiceDirection::Ausgabe, 40000, self::KAT_HALLE, self::KST_JUGEND, belegId: 4),
            new Posten(new \DateTimeImmutable('2025-09-10'), InvoiceDirection::Einnahme, 100000, self::KAT_MATERIAL, self::KST_HERREN, belegId: 5),
            new Posten(new \DateTimeImmutable('2024-12-31'), InvoiceDirection::Ausgabe, 99900, self::KAT_MATERIAL, self::KST_JUGEND, belegId: 6),
        ]);
    }

    /** @return list<int|null> id, Soll, Ist, count, Abweichung, Abweichung ‰, Ausschöpfung ‰ */
    private static function werte(Budgetzeile $z): array
    {
        return [$z->soll->id, $z->soll->betrag, $z->ist, $z->anzahl, $z->abweichung(), $z->abweichungPromille, $z->ausschoepfungPromille];
    }
}
