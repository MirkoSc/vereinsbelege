<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Domain\AssignmentRule;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionSetBy;
use App\Domain\Buchungsmerkmale;
use App\Service\Bank\BelegStandard;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Regelabgleich;
use PHPUnit\Framework\TestCase;

/**
 * Whether a rule matches a booking (M9-6, issue #64, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M9-6"), and what a new imported
 * booking gets from the rules. Plain values, no database.
 */
final class RegelabgleichTest extends TestCase
{
    private const string IBAN = 'DE89370400440532013000';

    public function testTheWordIsFoundInPurposeOrBookingTextIgnoringCaseAndSpaces(): void
    {
        $regel = self::regel(stichwort: 'Zinsen  abschluss');

        self::assertTrue(Regelabgleich::trifft($regel, self::buchung(zweck: 'ZINSEN ABSCHLUSS 3/26')));
        self::assertTrue(Regelabgleich::trifft($regel, self::buchung(buchungstext: "Zinsen\nAbschluss")));
        self::assertFalse(Regelabgleich::trifft($regel, self::buchung(zweck: 'Abschluss Zinsen')));
        self::assertFalse(Regelabgleich::trifft($regel, self::buchung(name: 'Zinsen Abschluss GmbH')), 'the word does not look at the name');
    }

    public function testACounterpartyNameIsContainedAndAnIbanMustBeEqual(): void
    {
        $name = self::regel(gegenseite: 'sparkasse');
        self::assertTrue(Regelabgleich::trifft($name, self::buchung(name: 'Kreis-Sparkasse Musterstadt')));
        self::assertFalse(Regelabgleich::trifft($name, self::buchung(zweck: 'Sparkasse')), 'the name does not look at the purpose');

        $iban = self::regel(gegenseite: self::IBAN);
        self::assertTrue($iban->gegenseiteIstIban());
        self::assertTrue(Regelabgleich::trifft($iban, self::buchung(iban: 'de89 3704 0044 0532 0130 00')));
        self::assertFalse(Regelabgleich::trifft($iban, self::buchung(iban: 'DE02120300000000202051')));
        self::assertFalse(Regelabgleich::trifft($iban, self::buchung(name: self::IBAN)), 'an IBAN is never matched against the name');
    }

    public function testWordAndCounterpartyMustBothMatch(): void
    {
        $regel = self::regel(stichwort: 'Entgelt', gegenseite: 'Sparkasse');

        self::assertTrue(Regelabgleich::trifft($regel, self::buchung(zweck: 'Entgelt 09/2026', name: 'Sparkasse')));
        self::assertFalse(Regelabgleich::trifft($regel, self::buchung(zweck: 'Entgelt 09/2026', name: 'Volksbank')));
        self::assertFalse(Regelabgleich::trifft($regel, self::buchung(zweck: 'Miete', name: 'Sparkasse')));
    }

    public function testADirectionNarrowsTheRule(): void
    {
        $regel = self::regel(stichwort: 'Zinsen', direction: BankTransactionDirection::Einnahme);

        self::assertTrue(Regelabgleich::trifft($regel, self::buchung(zweck: 'Zinsen', richtung: BankTransactionDirection::Einnahme)));
        self::assertFalse(Regelabgleich::trifft($regel, self::buchung(zweck: 'Zinsen', richtung: BankTransactionDirection::Ausgabe)));
    }

    public function testARuleWithoutConditionMatchesNothing(): void
    {
        self::assertFalse(Regelabgleich::trifft(self::regel(), self::buchung(zweck: 'irgendwas')));
    }

    public function testTheOldestActiveMatchingRuleWins(): void
    {
        $alt = self::regel(id: 3, stichwort: 'Entgelt');
        $neu = self::regel(id: 7, stichwort: 'Entgelt');
        $aeltesteInaktiv = self::regel(id: 1, stichwort: 'Entgelt', active: false);

        self::assertSame(3, Regelabgleich::ersteTreffende([$neu, $aeltesteInaktiv, $alt], self::buchung(zweck: 'Entgelt'))?->id);
        self::assertNull(Regelabgleich::ersteTreffende([$neu, $alt], self::buchung(zweck: 'Miete')));
    }

    public function testANewBookingGetsTheDefaultAndTheRuleOnTop(): void
    {
        $ohne = Buchungsregeln::fuerNeueBuchung([], new BelegStandard(), self::buchung(zweck: 'Entgelt'));
        self::assertTrue($ohne['docRequired'], 'an expense needs a receipt by default');
        self::assertSame(BankTransactionSetBy::Standard, $ohne['docSource']);
        self::assertNull($ohne['categoryId']);
        self::assertNull($ohne['ruleId']);

        $mit = Buchungsregeln::fuerNeueBuchung([self::regel(id: 4, stichwort: 'entgelt', categoryId: 12)], new BelegStandard(), self::buchung(zweck: 'Entgelt'));
        self::assertFalse($mit['docRequired']);
        self::assertSame(BankTransactionSetBy::Regel, $mit['docSource']);
        self::assertSame(12, $mit['categoryId']);
        self::assertSame(BankTransactionSetBy::Regel, $mit['categorySource']);
        self::assertSame(4, $mit['ruleId']);

        $nurKategorie = Buchungsregeln::fuerNeueBuchung([self::regel(id: 4, stichwort: 'entgelt', noReceipt: false, categoryId: 12)], new BelegStandard(), self::buchung(zweck: 'Entgelt'));
        self::assertTrue($nurKategorie['docRequired'], 'a rule without "kein Beleg nötig" leaves the receipt alone');
        self::assertSame(BankTransactionSetBy::Standard, $nurKategorie['docSource']);

        $einnahme = Buchungsregeln::fuerNeueBuchung([], new BelegStandard(einnahmeBelegNoetig: true), self::buchung(richtung: BankTransactionDirection::Einnahme));
        self::assertTrue($einnahme['docRequired'], 'the setting makes income need a receipt');
    }

    public function testTheDefaultFollowsTheSetting(): void
    {
        self::assertTrue(new BelegStandard()->noetig(BankTransactionDirection::Ausgabe));
        self::assertFalse(new BelegStandard()->noetig(BankTransactionDirection::Einnahme));
        self::assertTrue(new BelegStandard(einnahmeBelegNoetig: true)->noetig(BankTransactionDirection::Einnahme));
    }

    private static function regel(
        int $id = 1,
        string $stichwort = '',
        string $gegenseite = '',
        ?BankTransactionDirection $direction = null,
        bool $noReceipt = true,
        ?int $categoryId = null,
        bool $active = true,
    ): AssignmentRule {
        $jetzt = new \DateTimeImmutable('2026-10-09');

        return new AssignmentRule($id, 'Regel ' . $id, $stichwort, $gegenseite, $direction, $noReceipt, $categoryId, $active, $jetzt, $jetzt);
    }

    private static function buchung(
        string $zweck = '',
        string $buchungstext = '',
        string $name = '',
        string $iban = '',
        BankTransactionDirection $richtung = BankTransactionDirection::Ausgabe,
    ): Buchungsmerkmale {
        return new Buchungsmerkmale($richtung, $zweck, $buchungstext, $name, $iban);
    }
}
