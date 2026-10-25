<?php

declare(strict_types=1);

namespace App\Tests\Service\Report;

use App\Domain\Allocation;
use App\Domain\BankAccount;
use App\Domain\BankAccountData;
use App\Domain\BankAccountKind;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\BankTransactionSource;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceType;
use App\Domain\Zugriffsbereich;
use App\Domain\ZuordnungMethode;
use App\Service\Report\Auswertungsgrundlage;
use App\Service\Report\Belegklasse;
use App\Service\Report\Dimension;
use App\Service\Report\Grundlage;
use App\Service\Report\Kontobewegung;
use App\Service\Report\Posten;
use App\Service\Report\Postenbildung;
use App\Service\Report\ReportService;
use App\Service\Report\Summen;
use App\Service\Report\Zeitraum;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\Report\ReportService and Postenbildung (issue #71, docs/spec/
 * 05-auswertung-und-export.md section 1 and "Pflicht-Tests", E-44): sums by
 * category/supplier/month, receipts vs. bookings, previous year, the
 * cost-center scope of a "Vereinsverantwortlicher", transfers, credit
 * notes, balances. All amounts integer cents; made-up ids only.
 */
final class ReportServiceTest extends TestCase
{
    private const int KAT_MATERIAL = 1;
    private const int KAT_HALLE = 2;
    private const int KAT_BEITRAG = 9;
    private const int KST_JUGEND = 7;
    private const int KST_HERREN = 8;
    private const int LIEF_SPORT = 3;
    private const int LIEF_STADT = 4;
    private const int GIRO = 1;
    private const int KASSE = 2;

    // ------------------------------------------------------------ receipt basis

    public function testReceiptBasisSumsByCategoryWithCreditNotesReducingTheirSide(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-02-10', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 10000],
            [$this->beleg(2, '2026-03-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 5050],
            [$this->beleg(3, '2026-03-05', InvoiceDirection::Ausgabe, self::KAT_MATERIAL, typ: InvoiceType::Gutschrift), 3000],
            [$this->beleg(4, '2026-05-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 20000],
            [$this->beleg(5, '2026-06-01', InvoiceDirection::Einnahme, self::KAT_BEITRAG), 50000],
            [$this->beleg(6, '2026-07-01', InvoiceDirection::Ausgabe, null), 199],
            [$this->beleg(7, '2025-12-31', InvoiceDirection::Ausgabe, self::KAT_HALLE), 99999],
        ]);
        $r = new ReportService();

        $summen = $r->summen($g, Zugriffsbereich::unbeschraenkt());
        self::assertSame(50000, $summen->einnahmen);
        self::assertSame(10000 + 5050 - 3000 + 20000 + 199, $summen->ausgaben);
        self::assertSame(50000 - 32249, $summen->saldo());
        self::assertSame(6, $summen->anzahl);

        $gruppen = $this->karte($r->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie));
        self::assertSame([self::KAT_BEITRAG, self::KAT_HALLE, self::KAT_MATERIAL, ''], array_keys($gruppen));
        self::assertSame([0, 12050, 3], $this->werte($gruppen[self::KAT_MATERIAL]));
        self::assertSame([0, 20000, 1], $this->werte($gruppen[self::KAT_HALLE]));
        self::assertSame([50000, 0, 1], $this->werte($gruppen[self::KAT_BEITRAG]));
        self::assertSame([0, 199, 1], $this->werte($gruppen['']));

        $einnahmen = $r->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, InvoiceDirection::Einnahme);
        self::assertCount(1, $einnahmen);
        self::assertSame(self::KAT_BEITRAG, $einnahmen[0]->schluessel);
    }

    public function testMonthsAreInOrderAndIncludeEmptyMonths(): void
    {
        $g = $this->belege(Zeitraum::quartal(2026, 1), [
            [$this->beleg(1, '2026-03-31', InvoiceDirection::Ausgabe, self::KAT_HALLE), 700],
            [$this->beleg(2, '2026-01-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 300],
            [$this->beleg(3, '2026-01-15', InvoiceDirection::Einnahme, self::KAT_BEITRAG), 1000],
        ]);
        $monate = (new ReportService())->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Monat);

        self::assertSame(['2026-01', '2026-02', '2026-03'], array_map(static fn($m) => $m->schluessel, $monate));
        self::assertSame([1000, 300, 2], $this->werte($monate[0]->summen));
        self::assertSame([0, 0, 0], $this->werte($monate[1]->summen));
        self::assertSame([0, 700, 1], $this->werte($monate[2]->summen));
    }

    public function testTopSuppliersSumTheRestAndKeepReceiptsWithoutSupplierApart(): void
    {
        $belege = [];
        $id = 0;
        foreach ([[self::LIEF_SPORT, 5000], [self::LIEF_STADT, 9000], [11, 100], [12, 200], [12, 50], [null, 777]] as [$lieferant, $cent]) {
            $belege[] = [$this->beleg(++$id, '2026-04-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL, lieferant: $lieferant), $cent];
        }
        $liste = (new ReportService())->rangliste($this->belege(Zeitraum::jahr(2026), $belege), Zugriffsbereich::unbeschraenkt(), Dimension::Lieferant, 2);

        self::assertSame([self::LIEF_STADT, self::LIEF_SPORT], array_map(static fn($g) => $g->schluessel, $liste->top));
        self::assertSame([0, 350, 3], $this->werte($liste->uebrige));
        self::assertSame([0, 777, 1], $this->werte($liste->ohne));

        $alle = (new ReportService())->rangliste($this->belege(Zeitraum::jahr(2026), array_slice($belege, 0, 2)), Zugriffsbereich::unbeschraenkt(), Dimension::Lieferant);
        self::assertCount(2, $alle->top);
        self::assertNull($alle->uebrige);
        self::assertNull($alle->ohne);
    }

    public function testDrilldownListsTheLinesOfOneGroup(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(5, '2026-05-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 100],
            [$this->beleg(4, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 200],
            [$this->beleg(6, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 300],
            [$this->beleg(8, '2026-03-01', InvoiceDirection::Ausgabe, null), 400],
        ]);
        $r = new ReportService();

        self::assertSame([4, 5], array_map(static fn(Posten $p) => $p->belegId, $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, self::KAT_HALLE)));
        self::assertSame([8], array_map(static fn(Posten $p) => $p->belegId, $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, null)));
        self::assertSame([], $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, 999));
    }

    public function testACreditNoteEnteredWithANegativeGrossStillReducesItsSide(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-02-10', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 10000],
            [$this->beleg(2, '2026-02-11', InvoiceDirection::Ausgabe, self::KAT_MATERIAL, typ: InvoiceType::Gutschrift), -2000],
        ]);
        self::assertSame([0, 8000, 2], $this->werte((new ReportService())->summen($g, Zugriffsbereich::unbeschraenkt())));
    }

    public function testDrilldownAcceptsKeysAsTheyComeFromARequest(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-05-01', InvoiceDirection::Ausgabe, 12), 100],
            [$this->beleg(2, '2026-05-02', InvoiceDirection::Ausgabe, null), 100],
        ]);
        $r = new ReportService();
        self::assertSame([1], array_map(static fn(Posten $p) => $p->belegId, $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, '12')));
        self::assertSame([2], array_map(static fn(Posten $p) => $p->belegId, $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, '')));
        self::assertSame([1, 2], array_map(static fn(Posten $p) => $p->belegId, $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Monat, '2026-05')));
        self::assertSame([], $r->drilldown($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie, '12abc'));
    }

    public function testEqualGroupsSortByIdNumericallyAndAPeriodScopeGetsOnlyItsMonths(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-04-01', InvoiceDirection::Ausgabe, 10), 100],
            [$this->beleg(2, '2026-05-01', InvoiceDirection::Ausgabe, 9), 100],
        ]);
        $r = new ReportService();
        self::assertSame([9, 10], array_map(static fn($x) => $x->schluessel, $r->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie)));

        $extern = new Zugriffsbereich(null, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-06-30'));
        self::assertSame(['2026-04', '2026-05', '2026-06'], array_map(static fn($x) => $x->schluessel, $r->gruppiert($g, $extern, Dimension::Monat)));
        self::assertSame([], $r->gruppiert($g, new Zugriffsbereich(null, new \DateTimeImmutable('2027-01-01')), Dimension::Monat));
    }

    // ------------------------------------------------------------ receipts vs. bookings

    public function testReceiptsAndBookingsCountTheSamePaymentInTheirOwnPeriod(): void
    {
        // An invoice dated December, paid in January; income without a receipt;
        // a transfer from the bank account to the cash box.
        $rechnung = $this->beleg(1, '2025-12-20', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_JUGEND, lieferant: self::LIEF_STADT);
        $zahlung = $this->buchung(10, '2026-01-05', -12000, self::KAT_MATERIAL);
        $beitrag = $this->buchung(11, '2026-01-10', 50000, self::KAT_BEITRAG);
        $umbuchungAus = $this->buchung(12, '2026-01-15', -20000, null, umbuchung: true);
        $umbuchungEin = $this->buchung(13, '2026-01-15', 20000, null, konto: self::KASSE, umbuchung: true);

        $r = new ReportService();
        $belege2026 = $this->belege(Zeitraum::jahr(2026), [[$rechnung, 12000]]);
        $belege2025 = $this->belege(Zeitraum::jahr(2025), [[$rechnung, 12000]]);
        $buchungen = $this->buchungen(Zeitraum::jahr(2026), [
            [$zahlung, [$this->zuordnung(1, 1, 10, 12000)]],
            [$beitrag, []],
            [$umbuchungAus, []],
            [$umbuchungEin, []],
        ], [1 => $rechnung]);

        self::assertSame([0, 0, 0], $this->werte($r->summen($belege2026, Zugriffsbereich::unbeschraenkt())));
        self::assertSame([0, 12000, 1], $this->werte($r->summen($belege2025, Zugriffsbereich::unbeschraenkt())));
        // Booking basis: income without a receipt counts, transfers do not.
        self::assertSame([50000, 12000, 2], $this->werte($r->summen($buchungen, Zugriffsbereich::unbeschraenkt())));

        // The allocated part takes the receipt's classification, not the booking's.
        $kategorien = $this->karte($r->gruppiert($buchungen, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie));
        self::assertArrayNotHasKey(self::KAT_MATERIAL, $kategorien);
        self::assertSame([0, 12000, 1], $this->werte($kategorien[self::KAT_HALLE]));
        self::assertSame([self::LIEF_STADT, null], array_map(static fn($g) => $g->schluessel, $r->gruppiert($buchungen, Zugriffsbereich::unbeschraenkt(), Dimension::Lieferant)));
        self::assertSame([self::GIRO], array_map(static fn($g) => $g->schluessel, $r->gruppiert($buchungen, Zugriffsbereich::unbeschraenkt(), Dimension::Konto)));
    }

    public function testABookingIsSplitByItsAllocationsAndTheRestKeepsItsCategory(): void
    {
        $halle = $this->beleg(1, '2026-03-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_JUGEND);
        $material = $this->beleg(2, '2026-03-02', InvoiceDirection::Ausgabe, null, kostenstelle: self::KST_HERREN);
        $sammel = $this->buchung(20, '2026-03-10', -15000, self::KAT_MATERIAL);

        $posten = Postenbildung::ausBuchung($sammel, [
            $this->zuordnung(2, 2, 20, 4000),
            $this->zuordnung(1, 1, 20, 9000),
            $this->zuordnung(3, 99, 20, 1000), // receipt not checked yet: stays with the booking
        ], [1 => $halle, 2 => $material]);

        self::assertSame(
            [[self::KAT_HALLE, self::KST_JUGEND, 9000, 1], [self::KAT_MATERIAL, self::KST_HERREN, 4000, 2], [self::KAT_MATERIAL, null, 2000, null]],
            array_map(static fn(Posten $p) => [$p->kategorieId, $p->kostenstelleId, $p->betrag, $p->belegId], $posten),
        );
        self::assertSame(-15000, array_sum(array_map(static fn(Posten $p) => $p->saldo(), $posten)));
    }

    public function testAllocationsBeyondTheBookingAreCappedAndZeroOrTransferBookingsGiveNoLine(): void
    {
        $beleg = $this->beleg(1, '2026-03-01', InvoiceDirection::Ausgabe, self::KAT_HALLE);
        $posten = Postenbildung::ausBuchung($this->buchung(30, '2026-03-10', -5000, null), [
            $this->zuordnung(1, 1, 30, 4000),
            $this->zuordnung(2, 1, 30, 4000),
        ], [1 => $beleg]);

        self::assertSame([4000, 1000], array_map(static fn(Posten $p) => $p->betrag, $posten));
        self::assertSame([], Postenbildung::ausBuchung($this->buchung(31, '2026-03-10', 0, null), [], []));
        self::assertSame([], Postenbildung::ausBuchung($this->buchung(32, '2026-03-10', -5000, null, umbuchung: true), [$this->zuordnung(3, 1, 32, 5000)], [1 => $beleg]));
        // An allocation of another booking is ignored.
        self::assertSame([null], array_map(static fn(Posten $p) => $p->belegId, Postenbildung::ausBuchung($this->buchung(33, '2026-03-10', -5000, null), [$this->zuordnung(4, 1, 34, 5000)], [1 => $beleg])));
    }

    public function testARefundOfACreditNoteReducesExpensesOnBothBases(): void
    {
        $gutschrift = $this->beleg(1, '2026-04-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL, typ: InvoiceType::Gutschrift);
        $rueckzahlung = $this->buchung(40, '2026-04-08', 3000, self::KAT_BEITRAG);
        $r = new ReportService();

        $buchungen = $this->buchungen(Zeitraum::jahr(2026), [[$rueckzahlung, [$this->zuordnung(1, 1, 40, 3000)]]], [1 => $gutschrift]);
        $belege = $this->belege(Zeitraum::jahr(2026), [[$gutschrift, 3000]]);

        foreach ([$buchungen, $belege] as $g) {
            $summen = $r->summen($g, Zugriffsbereich::unbeschraenkt());
            self::assertSame([0, -3000, 1], $this->werte($summen));
            self::assertSame(3000, $summen->saldo());
            self::assertSame(self::KAT_MATERIAL, $r->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie)[0]->schluessel);
        }
    }

    // ------------------------------------------------------------ previous year

    public function testComparisonWithThePreviousYear(): void
    {
        $r = new ReportService();
        $aktuell = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-01-10', InvoiceDirection::Ausgabe, self::KAT_HALLE), 12000],
            [$this->beleg(2, '2026-02-10', InvoiceDirection::Einnahme, self::KAT_BEITRAG), 30000],
        ]);
        $vorjahr = $this->belege(Zeitraum::jahr(2026)->vorjahr(), [
            [$this->beleg(3, '2025-01-20', InvoiceDirection::Ausgabe, self::KAT_HALLE), 10000],
            [$this->beleg(4, '2025-03-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 2500],
            [$this->beleg(5, '2026-01-01', InvoiceDirection::Ausgabe, self::KAT_MATERIAL), 99999], // outside the previous year
        ]);

        $gesamt = $r->vergleich($aktuell, $vorjahr, Zugriffsbereich::unbeschraenkt());
        self::assertCount(1, $gesamt);
        self::assertSame([30000, 12000, 2], $this->werte($gesamt[0]->aktuell));
        self::assertSame([0, 12500, 2], $this->werte($gesamt[0]->vorjahr));
        self::assertSame([30000, -500, 0], $this->werte($gesamt[0]->differenz()));

        $kategorien = [];
        foreach ($r->vergleich($aktuell, $vorjahr, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie) as $zeile) {
            $kategorien[$zeile->schluessel] = [$zeile->aktuell->ausgaben, $zeile->vorjahr->ausgaben];
        }
        self::assertSame([self::KAT_BEITRAG => [0, 0], self::KAT_HALLE => [12000, 10000], self::KAT_MATERIAL => [0, 2500]], $kategorien);

        // "ohne Kategorie" stays last, also behind keys only the previous year has.
        $ohne = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(6, '2026-01-10', InvoiceDirection::Ausgabe, self::KAT_HALLE), 100],
            [$this->beleg(7, '2026-01-11', InvoiceDirection::Ausgabe, null), 100],
        ]);
        self::assertSame([self::KAT_HALLE, self::KAT_MATERIAL, null], array_map(static fn($z) => $z->schluessel, $r->vergleich($ohne, $vorjahr, Zugriffsbereich::unbeschraenkt(), Dimension::Kategorie)));

        $monate = $r->vergleich($aktuell, $vorjahr, Zugriffsbereich::unbeschraenkt(), Dimension::Monat);
        self::assertCount(12, $monate);
        self::assertSame(['2026-01', 12000, 10000], [$monate[0]->schluessel, $monate[0]->aktuell->ausgaben, $monate[0]->vorjahr->ausgaben]);
        self::assertSame(['2026-03', 0, 2500], [$monate[2]->schluessel, $monate[2]->aktuell->ausgaben, $monate[2]->vorjahr->ausgaben]);

        $this->expectException(\InvalidArgumentException::class);
        $r->vergleich($aktuell, new Auswertungsgrundlage(Grundlage::Buchungen, Zeitraum::jahr(2025), []), Zugriffsbereich::unbeschraenkt());
    }

    // ------------------------------------------------------------ scope

    public function testACostCenterScopeSeesOnlyItsCostCenters(): void
    {
        $jugend = new Zugriffsbereich([self::KST_JUGEND]);
        $r = new ReportService();
        $belege = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_JUGEND), 1000],
            [$this->beleg(2, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_HERREN), 2000],
            [$this->beleg(3, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 4000],
        ]);
        self::assertSame([0, 1000, 1], $this->werte($r->summen($belege, $jugend)));
        self::assertSame([0, 0, 0], $this->werte($r->summen($belege, new Zugriffsbereich([]))));
        self::assertSame([0, 3000, 2], $this->werte($r->summen($belege, new Zugriffsbereich([self::KST_JUGEND, self::KST_HERREN]))));
        self::assertSame([self::KST_JUGEND], array_map(static fn($g) => $g->schluessel, $r->gruppiert($belege, $jugend, Dimension::Kostenstelle)));

        // Booking basis: only the parts allocated to its receipts, never the rest.
        $buchungen = $this->buchungen(Zeitraum::jahr(2026), [
            [$this->buchung(50, '2026-02-05', -7000, self::KAT_MATERIAL), [$this->zuordnung(1, 1, 50, 1000), $this->zuordnung(2, 2, 50, 2000)]],
            [$this->buchung(51, '2026-02-06', 9000, self::KAT_BEITRAG), []],
        ], [
            1 => $this->beleg(1, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_JUGEND),
            2 => $this->beleg(2, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, kostenstelle: self::KST_HERREN),
        ]);
        self::assertSame([0, 1000, 1], $this->werte($r->summen($buchungen, $jugend)));
        self::assertSame([9000, 7000, 4], $this->werte($r->summen($buchungen, Zugriffsbereich::unbeschraenkt())));
    }

    public function testAPeriodScopeSeesOnlyItsPeriod(): void
    {
        $extern = new Zugriffsbereich(null, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-02-28', InvoiceDirection::Ausgabe, self::KAT_HALLE), 1000],
            [$this->beleg(2, '2026-03-15', InvoiceDirection::Ausgabe, self::KAT_HALLE), 2000],
            [$this->beleg(3, '2026-04-01', InvoiceDirection::Ausgabe, self::KAT_HALLE), 4000],
        ]);
        self::assertSame([0, 2000, 1], $this->werte((new ReportService())->summen($g, $extern)));
    }

    public function testSpheresAreOnlyReportedWhenSwitchedOn(): void
    {
        $g = $this->belege(Zeitraum::jahr(2026), [
            [$this->beleg(1, '2026-02-01', InvoiceDirection::Ausgabe, self::KAT_HALLE, sphaere: 'ideell'), 1000],
            [$this->beleg(2, '2026-02-01', InvoiceDirection::Einnahme, self::KAT_BEITRAG, sphaere: 'zweckbetrieb'), 2000],
        ]);
        self::assertSame(['zweckbetrieb', 'ideell'], array_map(static fn($s) => $s->schluessel, (new ReportService(true))->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Sphaere)));

        $this->expectException(\DomainException::class);
        (new ReportService())->gruppiert($g, Zugriffsbereich::unbeschraenkt(), Dimension::Sphaere);
    }

    // ------------------------------------------------------------ balances

    public function testBalancesIncludeTransfersAndStartAtTheOpeningDate(): void
    {
        $konten = [
            $this->konto(self::GIRO, 100000, '2025-01-01'),
            $this->konto(self::KASSE, 5000, '2026-03-01'),
            $this->konto(5, 0, '2027-01-01'),
        ];
        $bewegungen = [
            new Kontobewegung(self::GIRO, new \DateTimeImmutable('2025-06-01'), -30000),
            new Kontobewegung(self::GIRO, new \DateTimeImmutable('2026-01-10'), 50000),
            new Kontobewegung(self::GIRO, new \DateTimeImmutable('2026-03-15'), -20000), // transfer to the cash box
            new Kontobewegung(self::KASSE, new \DateTimeImmutable('2026-03-15'), 20000),
            new Kontobewegung(self::KASSE, new \DateTimeImmutable('2026-02-01'), 99999), // before the opening date
            new Kontobewegung(self::KASSE, new \DateTimeImmutable('2026-04-01'), -1500),
            new Kontobewegung(self::GIRO, new \DateTimeImmutable('2027-01-02'), 77777),
        ];
        $r = new ReportService();

        $salden = $r->kontosalden($konten, $bewegungen, Zeitraum::jahr(2026), Zugriffsbereich::unbeschraenkt());
        self::assertSame([
            [self::GIRO, 70000, 0, 50000, 20000, 100000],
            [self::KASSE, 0, 5000, 20000, 1500, 23500],
        ], array_map(static fn($s) => [$s->kontoId, $s->anfang, $s->eroeffnung, $s->zugaenge, $s->abgaenge, $s->ende], $salden));

        self::assertSame([self::GIRO => 120000], $r->kontostaende($konten, $bewegungen, new \DateTimeImmutable('2026-02-28'), Zugriffsbereich::unbeschraenkt()));
        self::assertSame([self::GIRO => 100000, self::KASSE => 23500], $r->kontostaende($konten, $bewegungen, new \DateTimeImmutable('2026-12-31'), Zugriffsbereich::unbeschraenkt()));

        // Bookings carry no cost center: no balances for a cost-center scope.
        self::assertSame([], $r->kontosalden($konten, $bewegungen, Zeitraum::jahr(2026), new Zugriffsbereich([self::KST_JUGEND])));
        self::assertSame([], $r->kontostaende($konten, $bewegungen, new \DateTimeImmutable('2026-12-31'), new Zugriffsbereich([self::KST_JUGEND])));

        // A period scope narrows the period; the start balance still counts what came before.
        $extern = new Zugriffsbereich(null, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));
        self::assertSame(
            [[self::GIRO, 120000, 0, 0, 20000, 100000], [self::KASSE, 0, 5000, 20000, 0, 25000]],
            array_map(static fn($s) => [$s->kontoId, $s->anfang, $s->eroeffnung, $s->zugaenge, $s->abgaenge, $s->ende], $r->kontosalden($konten, $bewegungen, Zeitraum::jahr(2026), $extern)),
        );
        self::assertSame([], $r->kontostaende($konten, $bewegungen, new \DateTimeImmutable('2026-12-31'), $extern));
    }

    // ------------------------------------------------------------ helpers

    private function beleg(
        int $id,
        string $datum,
        InvoiceDirection $richtung,
        ?int $kategorie,
        InvoiceType $typ = InvoiceType::Rechnung,
        ?int $kostenstelle = null,
        ?int $lieferant = null,
        ?string $sphaere = null,
    ): Belegklasse {
        return new Belegklasse($id, new \DateTimeImmutable($datum), $richtung, $typ, $kategorie, $kostenstelle, $lieferant, $sphaere);
    }

    /** @param list<array{0: Belegklasse, 1: int}> $belege */
    private function belege(Zeitraum $zeitraum, array $belege): Auswertungsgrundlage
    {
        return new Auswertungsgrundlage(Grundlage::Belege, $zeitraum, array_map(static fn(array $b): Posten => Postenbildung::ausBeleg($b[0], $b[1]), $belege));
    }

    /**
     * @param list<array{0: BankTransaction, 1: list<Allocation>}> $buchungen
     * @param array<int, Belegklasse> $belege
     */
    private function buchungen(Zeitraum $zeitraum, array $buchungen, array $belege): Auswertungsgrundlage
    {
        $posten = [];
        foreach ($buchungen as [$buchung, $zuordnungen]) {
            array_push($posten, ...Postenbildung::ausBuchung($buchung, $zuordnungen, $belege));
        }

        return new Auswertungsgrundlage(Grundlage::Buchungen, $zeitraum, $posten);
    }

    private function buchung(int $id, string $datum, int $cent, ?int $kategorie, int $konto = self::GIRO, bool $umbuchung = false): BankTransaction
    {
        $jetzt = new \DateTimeImmutable('2026-10-01');

        return new BankTransaction(
            id: $id,
            accountId: $konto,
            importId: null,
            bookingDate: new \DateTimeImmutable($datum),
            valueDate: null,
            direction: BankTransactionDirection::ausDemBetrag($cent),
            categoryId: $kategorie,
            docRequired: !$umbuchung,
            docStatus: $umbuchung ? BankTransactionDocStatus::NichtNoetig : BankTransactionDocStatus::fuerNeueBuchung(true),
            source: BankTransactionSource::Import,
            amount: $cent,
            currency: 'EUR',
            counterpartyName: 'Gegenseite',
            counterpartyIban: '',
            purpose: 'Zweck',
            bookingText: '',
            createdAt: $jetzt,
            updatedAt: $jetzt,
            docSource: $umbuchung ? BankTransactionSetBy::Umbuchung : BankTransactionSetBy::Standard,
        );
    }

    private function zuordnung(int $id, int $beleg, int $buchung, int $cent): Allocation
    {
        return new Allocation($id, $beleg, $buchung, $cent, ZuordnungMethode::Manuell, null, null, null, new \DateTimeImmutable('2026-10-01'));
    }

    private function konto(int $id, int $eroeffnung, string $datum): BankAccount
    {
        $jetzt = new \DateTimeImmutable('2026-10-01');

        return new BankAccount($id, BankAccountKind::Bank, new BankAccountData('Konto ' . $id), $eroeffnung, 'EUR', new \DateTimeImmutable($datum), true, $jetzt, $jetzt);
    }

    /**
     * @param list<\App\Service\Report\Gruppe> $gruppen
     *
     * @return array<int|string, Summen> by key, '' for none
     */
    private function karte(array $gruppen): array
    {
        $karte = [];
        foreach ($gruppen as $g) {
            $karte[$g->schluessel ?? ''] = $g->summen;
        }

        return $karte;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function werte(?Summen $s): array
    {
        self::assertNotNull($s);

        return [$s->einnahmen, $s->ausgaben, $s->anzahl];
    }
}

