<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Domain\Zahlungsweg;
use App\Service\Bank\AbgleichBeleg;
use App\Service\Bank\AbgleichBuchung;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Punktebewertung;
use App\Service\Bank\Signal;
use PHPUnit\Framework\TestCase;

/**
 * Scoring receipt <-> booking and the automatic decision (M10-2, issue #66,
 * docs/spec/04-bank-und-abgleich.md section 5 "Punktebewertung"). Mandatory
 * tests of the spec for this scope: every signal on its own, the automatic
 * threshold and the distance rule, collective transfer, instalment, cash
 * discount. Plain values, no database, no vault.
 */
final class PunktebewertungTest extends TestCase
{
    private const string IBAN_LIEFERANT = 'DE89370400440532013000';

    // ------------------------------------------------------- each signal

    public function testOnlyTheDateWindowFiresForAnUnrelatedBookingOfTheSameDirection(): void
    {
        $b = $this->bewerten($this->beleg(), $this->buchung(offen: 777));

        self::assertSame([Signal::DatumImFenster->value => 10], $b->signale);
        self::assertSame(10, $b->score);
    }

    public function testAnExactAmountScores45(): void
    {
        $b = $this->bewerten($this->beleg(offen: 11900), $this->buchung(offen: 11900));

        self::assertSame(45, $b->signale[Signal::BetragExakt->value]);
        self::assertTrue($b->vollGedeckt);
        self::assertSame(11900, $b->betrag);
    }

    public function testACashDiscountUpToThreePercentBelowScores20AndNothingAboveOrBeyond(): void
    {
        $beleg = $this->beleg(offen: 10000);

        self::assertSame(20, $this->bewerten($beleg, $this->buchung(offen: 9700))->signale[Signal::BetragSkonto->value] ?? null);
        self::assertArrayNotHasKey(Signal::BetragSkonto->value, $this->bewerten($beleg, $this->buchung(offen: 9699))->signale, 'more than 3 % less');
        $mehr = $this->bewerten($beleg, $this->buchung(offen: 10100))->signale;
        self::assertArrayNotHasKey(Signal::BetragSkonto->value, $mehr, 'more is never a discount');
        self::assertArrayNotHasKey(Signal::BetragExakt->value, $mehr);
    }

    public function testTheInvoiceNumberInThePurposeIsComparedNormalised(): void
    {
        $beleg = $this->beleg(nummer: 'RE-2026/0815');

        self::assertSame(40, $this->bewerten($beleg, $this->buchung(zweck: 'Rechnung re 2026 0815 vom 3.3.'))->signale[Signal::Rechnungsnummer->value] ?? null);
        self::assertSame(40, $this->bewerten($beleg, $this->buchung(zweck: 'Danke', eref: 'RE20260815'))->signale[Signal::Rechnungsnummer->value] ?? null, 'the end-to-end reference counts');
        self::assertSame(40, $this->bewerten($this->beleg(nummer: '4711-1'), $this->buchung(zweck: 'Zahlung vom 15.03.2026', eref: '47111'))->signale[Signal::Rechnungsnummer->value] ?? null, 'purpose and reference stay apart');
        self::assertArrayNotHasKey(Signal::Rechnungsnummer->value, $this->bewerten($beleg, $this->buchung(zweck: 'RE-2026/08151'))->signale, 'a longer number is another number');
    }

    public function testShortOrDigitlessNumbersSayNothing(): void
    {
        $suche = static fn(string $text, string $kennung): bool => Punktebewertung::enthaeltKennung(Punktebewertung::suchtext($text), $kennung);
        self::assertFalse($suche('RE12 abc', '12'));
        self::assertFalse($suche('Mitgliedsbeitrag', 'beitrag'));
        self::assertFalse($suche('20260815', '815'), 'a number edge must not touch another digit');
        self::assertTrue($suche('Kd4711Re9', '4711'));
        self::assertTrue($suche('20260815 x815x', '815'), 'a later clean occurrence counts');
        self::assertTrue($suche('Zahlung vom 15.03.2026 4711', '4711'), 'a separator is a boundary');
        self::assertTrue($suche('Kd 123456 / 4711', '4711'));
        self::assertFalse($suche('Kd 1234567', '4567'));
    }

    public function testTheSupplierIbanScores30(): void
    {
        $b = $this->bewerten($this->beleg(), $this->buchung(iban: 'de89 3704 0044 0532 0130 00'));

        self::assertSame(30, $b->signale[Signal::Iban->value] ?? null);
        self::assertArrayNotHasKey(Signal::Iban->value, $this->bewerten($this->beleg(), $this->buchung(iban: 'DE02120300000000202051'))->signale);
    }

    public function testAKnownMandateOrCreditorIdScores30Once(): void
    {
        self::assertSame(30, $this->bewerten($this->beleg(), $this->buchung(mref: 'm-123 45'))->signale[Signal::Mandat->value] ?? null);
        self::assertSame(30, $this->bewerten($this->beleg(), $this->buchung(cred: 'de98zzz09999999999'))->signale[Signal::Mandat->value] ?? null);
        self::assertSame(30, $this->bewerten($this->beleg(), $this->buchung(mref: 'M-12345', cred: 'DE98ZZZ09999999999'))->signale[Signal::Mandat->value] ?? null);
        self::assertArrayNotHasKey(Signal::Mandat->value, $this->bewerten($this->beleg(), $this->buchung(mref: 'M-999'))->signale);
    }

    public function testASimilarCounterpartyNameScores15(): void
    {
        self::assertSame(15, $this->bewerten($this->beleg(), $this->buchung(name: 'GETRAENKE MUELLER GMBH'))->signale[Signal::Name->value] ?? null);
        self::assertSame(15, $this->bewerten($this->beleg(), $this->buchung(name: 'Mueller Getraenkeservice'))->signale[Signal::Name->value] ?? null, 'an alias counts');
        self::assertArrayNotHasKey(Signal::Name->value, $this->bewerten($this->beleg(), $this->buchung(name: 'Stadtwerke'))->signale);
    }

    public function testTheCustomerNumberInThePurposeScores15(): void
    {
        self::assertSame(15, $this->bewerten($this->beleg(), $this->buchung(zweck: 'Kd-Nr. 4711 Lieferung'))->signale[Signal::Kundennummer->value] ?? null);
    }

    public function testTheDateWindowRunsFromFiveDaysBeforeTheInvoiceTo45DaysAfterTheDueDate(): void
    {
        $beleg = $this->beleg(datum: '2026-03-10', faellig: '2026-03-24');

        foreach (['2026-03-05' => Signal::DatumImFenster, '2026-05-08' => Signal::DatumImFenster, '2026-03-04' => Signal::DatumAusserhalb, '2026-05-09' => Signal::DatumAusserhalb] as $tag => $signal) {
            $signale = $this->bewerten($beleg, $this->buchung(datum: $tag))->signale;
            self::assertArrayHasKey($signal->value, $signale, $tag);
        }
        self::assertSame(-20, $this->bewerten($beleg, $this->buchung(datum: '2026-01-01'))->signale[Signal::DatumAusserhalb->value]);
        self::assertArrayHasKey(Signal::DatumImFenster->value, $this->bewerten($this->beleg(datum: '2026-03-10'), $this->buchung(datum: '2026-04-24'))->signale, 'without a due date the invoice date counts');
    }

    public function testAReimbursementToTheSubmittersIbanOfTheFullSumScores60(): void
    {
        $beleg = $this->beleg(weg: Zahlungsweg::Erstattung, offen: 2350, einreicherIban: 'DE02120300000000202051');

        self::assertSame(60, $this->bewerten($beleg, $this->buchung(offen: 2350, iban: 'DE02 1203 0000 0000 2020 51'))->signale[Signal::Erstattung->value] ?? null);
        self::assertArrayNotHasKey(Signal::Erstattung->value, $this->bewerten($beleg, $this->buchung(offen: 2000, iban: 'DE02120300000000202051'))->signale);
        self::assertArrayNotHasKey(Signal::Erstattung->value, $this->bewerten($this->beleg(offen: 2350, einreicherIban: 'DE02120300000000202051'), $this->buchung(offen: 2350, iban: 'DE02120300000000202051'))->signale, 'only for reimbursements');
    }

    public function testOnlyPairsThatCouldBeAllocatedAreScored(): void
    {
        self::assertNull(Punktebewertung::bewerten($this->beleg(), $this->buchung(richtung: BankTransactionDirection::Einnahme), new Abgleichsparameter()));
        self::assertNull(Punktebewertung::bewerten($this->beleg(offen: 0), $this->buchung(), new Abgleichsparameter()));
        self::assertNull(Punktebewertung::bewerten($this->beleg(), $this->buchung(offen: 0), new Abgleichsparameter()));
    }

    public function testThePointsComeFromTheParameters(): void
    {
        $p = Abgleichsparameter::ausWerten(['punkte' => [Signal::BetragExakt->value => 50, Signal::DatumImFenster->value => 0]]);
        $b = Punktebewertung::bewerten($this->beleg(offen: 500), $this->buchung(offen: 500), $p);

        self::assertSame(50, $b?->score);
        self::assertSame(['v' => 1, 'signale' => [Signal::BetragExakt->value => 50, Signal::DatumImFenster->value => 0]], $b?->trace(), 'keys and points only');
    }

    // --------------------------------------------- automatic or suggestion

    public function testAnUnambiguousPairAtTheThresholdIsAllocatedAutomatically(): void
    {
        // 45 amount + 30 IBAN + 10 date = 85.
        $plan = Punktebewertung::planen([$this->beleg(id: 1, offen: 5000)], [$this->buchung(id: 9, offen: 5000, iban: self::IBAN_LIEFERANT)], new Abgleichsparameter());

        self::assertCount(1, $plan->auto);
        self::assertSame([1, 9, 85], [$plan->auto[0]->invoiceId, $plan->auto[0]->transactionId, $plan->auto[0]->score]);
        self::assertSame([], $plan->vorschlaege, 'an automatic pair is no suggestion as well');
    }

    public function testBelowTheThresholdItIsOnlyASuggestion(): void
    {
        // 45 amount + 15 name + 10 date = 70.
        $plan = Punktebewertung::planen([$this->beleg(id: 1, offen: 5000)], [$this->buchung(id: 9, offen: 5000, name: 'Getränke Müller')], new Abgleichsparameter());

        self::assertSame([], $plan->auto);
        self::assertCount(1, $plan->vorschlaege);
        self::assertSame(70, $plan->vorschlaege[0]->score);
    }

    public function testTheRunnerUpOfTheReceiptMustBe20Behind(): void
    {
        $beleg = $this->beleg(id: 1, offen: 5000, nummer: 'RE-5512');
        $beste = $this->buchung(id: 9, offen: 5000, iban: self::IBAN_LIEFERANT, zweck: 'RE-5512');   // 45+40+30+10 = 125
        $knapp = $this->buchung(id: 8, offen: 5000, zweck: 'RE-5512', name: 'Getränke Müller');     // 45+40+15+10 = 110
        $weit = $this->buchung(id: 7, offen: 5000, zweck: 'RE-5512');                               // 45+40+10 = 95

        self::assertSame([], Punktebewertung::planen([$beleg], [$beste, $knapp], new Abgleichsparameter())->auto, '15 points ahead is not enough');
        $plan = Punktebewertung::planen([$beleg], [$beste, $weit], new Abgleichsparameter());
        self::assertSame([9], array_map(static fn($b): int => $b->transactionId, $plan->auto), '30 points ahead is');
    }

    public function testTheRunnerUpOfTheBookingMustBe20BehindAsWell(): void
    {
        // Two receipts of the same supplier over the same amount, one booking.
        $eins = $this->beleg(id: 1, offen: 5000);
        $zwei = $this->beleg(id: 2, offen: 5000);
        $buchung = $this->buchung(id: 9, offen: 5000, iban: self::IBAN_LIEFERANT);

        $plan = Punktebewertung::planen([$eins, $zwei], [$buchung], new Abgleichsparameter());

        self::assertSame([], $plan->auto, 'a tie is never unambiguous');
        self::assertCount(2, $plan->vorschlaege);
    }

    public function testFuehrtMitAbstand(): void
    {
        self::assertTrue(Punktebewertung::fuehrtMitAbstand([90], 90, 20));
        self::assertTrue(Punktebewertung::fuehrtMitAbstand([70, 90], 90, 20));
        self::assertFalse(Punktebewertung::fuehrtMitAbstand([71, 90], 90, 20));
        self::assertFalse(Punktebewertung::fuehrtMitAbstand([90, 90], 90, 0), 'a tie never leads');
        self::assertFalse(Punktebewertung::fuehrtMitAbstand([95, 90], 90, 0), 'not the best');
    }

    public function testACollectiveTransferIsNeverAllocatedAutomatically(): void
    {
        // One booking of 150 € for an invoice of 100 € (and another one missing).
        $plan = Punktebewertung::planen(
            [$this->beleg(id: 1, offen: 10000, nummer: 'RE-1001')],
            [$this->buchung(id: 9, offen: 15000, iban: self::IBAN_LIEFERANT, zweck: 'RE-1001 RE-1002', mref: 'M-12345')],
            new Abgleichsparameter(),
        );

        self::assertSame([], $plan->auto, 'the amount does not settle both sides');
        self::assertCount(1, $plan->vorschlaege);
        self::assertSame(10000, $plan->vorschlaege[0]->betrag, 'the suggestion takes what the receipt has open');
        self::assertFalse($plan->vorschlaege[0]->vollGedeckt);
    }

    public function testAnInstalmentIsASuggestionForWhatTheBookingBrings(): void
    {
        $plan = Punktebewertung::planen(
            [$this->beleg(id: 1, offen: 60000, nummer: 'RE-2001')],
            [$this->buchung(id: 9, offen: 20000, iban: self::IBAN_LIEFERANT, zweck: 'Rate 1 RE-2001')],
            new Abgleichsparameter(),
        );

        self::assertSame([], $plan->auto);
        self::assertSame(20000, $plan->vorschlaege[0]->betrag);
        self::assertArrayNotHasKey(Signal::BetragExakt->value, $plan->vorschlaege[0]->signale);
    }

    public function testACashDiscountIsASuggestionNotAutomatic(): void
    {
        $plan = Punktebewertung::planen(
            [$this->beleg(id: 1, offen: 10000, nummer: 'RE-3001')],
            [$this->buchung(id: 9, offen: 9800, iban: self::IBAN_LIEFERANT, zweck: 'RE-3001 abzgl. 2% Skonto')],
            new Abgleichsparameter(),
        );

        self::assertSame([], $plan->auto, '20+40+30+10 = 100 points, but the receipt stays partly open');
        self::assertSame(100, $plan->vorschlaege[0]->score);
        self::assertSame(9800, $plan->vorschlaege[0]->betrag);
    }

    public function testSuggestionsBelowTheMinimumAreLeftOutAndAtMostThreePerReceipt(): void
    {
        $buchungen = [];
        for ($i = 1; $i <= 5; $i++) {
            $buchungen[] = $this->buchung(id: $i, offen: 5000);   // 45 + 10 = 55 each
        }
        $buchungen[] = $this->buchung(id: 99, offen: 1);         // 10
        $plan = Punktebewertung::planen([$this->beleg(id: 1, offen: 5000)], $buchungen, new Abgleichsparameter());

        self::assertSame([1, 2, 3], array_map(static fn($b): int => $b->transactionId, $plan->vorschlaege));
    }

    // ------------------------------------------------------- parameters

    public function testParametersFallBackToTheStartValues(): void
    {
        $p = Abgleichsparameter::ausWerten(['punkte' => ['iban' => 'viel', 'name' => 999], 'auto_schwelle' => -5, 'auto_abstand' => 25]);

        self::assertSame(30, $p->punkte(Signal::Iban));
        self::assertSame(15, $p->punkte(Signal::Name));
        self::assertSame(85, $p->autoSchwelle);
        self::assertSame(25, $p->autoAbstand);
        self::assertSame(Abgleichsparameter::PUNKTE_START, new Abgleichsparameter()->punkte);
    }

    public function testTheFormNeedsWholeNumbersInRange(): void
    {
        $felder = [];
        foreach (Abgleichsparameter::PUNKTE_START as $signal => $start) {
            $felder['punkte_' . $signal] = (string) $start;
        }
        foreach (Abgleichsparameter::WERTE as $name => [$start]) {
            $felder[$name] = (string) $start;
        }
        self::assertSame(new Abgleichsparameter()->alsWerte(), Abgleichsparameter::ausFormular($felder)->alsWerte());

        try {
            Abgleichsparameter::ausFormular([...$felder, 'auto_schwelle' => '8,5']);
            self::fail('no whole number');
        } catch (BankRuleViolation $e) {
            self::assertSame('auto_schwelle', $e->feld);
        }
        $this->expectException(BankRuleViolation::class);
        Abgleichsparameter::ausFormular([...$felder, 'punkte_iban' => '201']);
    }

    // ------------------------------------------------------------ helpers

    private function bewerten(AbgleichBeleg $beleg, AbgleichBuchung $buchung): \App\Service\Bank\Bewertung
    {
        return Punktebewertung::bewerten($beleg, $buchung, new Abgleichsparameter()) ?? self::fail('not a candidate');
    }

    private function beleg(
        int $id = 1,
        int $offen = 10000,
        string $nummer = '',
        string $datum = '2026-03-10',
        ?string $faellig = null,
        Zahlungsweg $weg = Zahlungsweg::Konto,
        ?string $einreicherIban = null,
    ): AbgleichBeleg {
        return new AbgleichBeleg(
            id: $id,
            buchungsrichtung: BankTransactionDirection::Ausgabe,
            zahlungsweg: $weg,
            brutto: $offen,
            offen: $offen,
            rechnungsnummer: $nummer,
            rechnungsdatum: new \DateTimeImmutable($datum),
            faelligkeit: $faellig === null ? null : new \DateTimeImmutable($faellig),
            lieferantNamen: ['Getränke Müller GmbH', 'Müller Getränkeservice'],
            lieferantIbans: [self::IBAN_LIEFERANT],
            mandatsreferenzen: ['M-12345'],
            glaeubigerId: 'DE98ZZZ09999999999',
            kundennummer: '4711',
            einreicherIban: $einreicherIban,
        );
    }

    private function buchung(
        int $id = 9,
        int $offen = 3333,
        string $datum = '2026-03-20',
        string $name = '',
        string $iban = '',
        string $zweck = '',
        string $eref = '',
        string $mref = '',
        string $cred = '',
        BankTransactionDirection $richtung = BankTransactionDirection::Ausgabe,
    ): AbgleichBuchung {
        return new AbgleichBuchung($id, $richtung, $offen, new \DateTimeImmutable($datum), $name, $iban, $zweck, $eref, $mref, $cred);
    }
}
