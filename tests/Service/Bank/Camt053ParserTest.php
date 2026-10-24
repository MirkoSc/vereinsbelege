<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\Camt053Ergebnis;
use App\Service\Bank\Camt053Exception;
use App\Service\Bank\Camt053Parser;
use App\Service\Bank\Kontoauszug;
use App\Service\Bank\Mt940Parser;
use App\Service\Bank\Umsatz;
use App\Service\Bank\Umsatzdetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CAMT.053 import (issue #91, docs/spec/04-bank-und-abgleich.md section 2a
 * "Pflicht-Tests"): versions .02/.04/.08 regardless of the namespace, ZIP
 * with several statements, balances PRCD/OPBD/CLBD/ITBD, only booked
 * entries, reversal, batch, counterparty by direction, SEPA references,
 * purpose joined, refusals that never quote the file.
 *
 * The fixtures are synthetic, see tests/fixtures/camt053/README.md.
 */
final class Camt053ParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/camt053/';

    private static function inhalt(string $datei): string
    {
        return (string) file_get_contents(self::FIXTURES . $datei);
    }

    private static function lies(string $datei): Camt053Ergebnis
    {
        return new Camt053Parser()->parse(self::inhalt($datei));
    }

    private static function details(Umsatz $umsatz): Umsatzdetails
    {
        self::assertNotNull($umsatz->details);

        return $umsatz->details;
    }

    private static function datum(\DateTimeImmutable $datum): string
    {
        return $datum->format('Y-m-d');
    }

    public function testVersion02WithTwoStatementsAndPreviousClosingBalance(): void
    {
        $ergebnis = self::lies('sparkasse-053-001-02.xml');

        self::assertSame(0, $ergebnis->vorgemerkt);
        self::assertCount(2, $ergebnis->auszuege);
        [$erster, $zweiter] = $ergebnis->auszuege;

        self::assertSame('2024031100012', $erster->referenz);
        self::assertSame('12', $erster->auszugsnummer);
        self::assertSame('DE09123456780001234567', $erster->konto->iban);
        self::assertSame('DE09123456780001234567', $erster->konto->roh);
        self::assertNull($erster->konto->blz);

        // PRCD is the opening balance, dated the day of the previous statement.
        self::assertSame('2024-03-08', self::datum($erster->anfangssaldo->datum));
        self::assertSame(152340, $erster->anfangssaldo->cent);
        self::assertSame('EUR', $erster->anfangssaldo->waehrung);
        self::assertFalse($erster->anfangssaldo->zwischensaldo);
        self::assertSame('2024-03-11', self::datum($erster->schlusssaldo->datum));
        self::assertSame(160750, $erster->schlusssaldo->cent);
        self::assertFalse($erster->schlusssaldo->zwischensaldo);
        self::assertSame([-4590, 25000, -12000], array_map(static fn(Umsatz $u): int => $u->cent, $erster->umsaetze));
        self::assertSame([4590, -350], array_map(static fn(Umsatz $u): int => $u->cent, $zweiter->umsaetze));
        self::assertTrue($erster->saldoStimmt());
        self::assertTrue($zweiter->saldoStimmt());
    }

    public function testADirectDebitCarriesTheSepaReferencesOfMt940(): void
    {
        $umsatz = self::lies('sparkasse-053-001-02.xml')->auszuege[0]->umsaetze[0];
        $d = self::details($umsatz);

        self::assertSame('2024-03-11', self::datum($umsatz->buchungsdatum));
        self::assertSame('2024-03-11', self::datum($umsatz->valuta));
        self::assertFalse($umsatz->storno);
        self::assertSame('NDDT', $umsatz->buchungsschluessel);
        self::assertSame('RE-2024-0311-77', $umsatz->kundenreferenz);
        self::assertSame('2024031100001', $umsatz->bankreferenz);
        self::assertNull($umsatz->zusatz);

        self::assertTrue($d->strukturiert);
        self::assertSame('105', $d->gvc);
        self::assertSame('931', $d->textschluesselergaenzung);
        self::assertSame('FOLGELASTSCHRIFT', $d->buchungstext);
        self::assertSame('Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234', $d->verwendungszweck);
        self::assertSame('Getränke Müller GmbH & Co. KG Musterstadt', $d->name, 'A debit names the creditor.');
        self::assertSame('DE40120505550001234567', $d->iban);
        self::assertSame('GENODEF1XXX', $d->bic);
        self::assertSame([
            'CRED' => 'DE98ZZZ09999999999',
            'EREF' => 'RE-2024-0311-77',
            'MREF' => 'M-0815',
            'SVWZ' => 'Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234',
        ], $d->sepa);
    }

    public function testACreditNamesTheDebtorAndNotProvidedIsNoReference(): void
    {
        $umsatz = self::lies('sparkasse-053-001-02.xml')->auszuege[0]->umsaetze[1];
        $d = self::details($umsatz);

        self::assertSame('Schäfer, Jürgen', $d->name);
        self::assertSame('DE23120906400007654321', $d->iban);
        self::assertSame('BYLADEM1XXX', $d->bic);
        self::assertSame('NONREF', $umsatz->kundenreferenz);
        self::assertNull($d->sepa('EREF'));
        self::assertSame('166', $d->gvc);
    }

    public function testUltimateDebtorBecomesAbwa(): void
    {
        $d = self::details(self::lies('sparkasse-053-001-02.xml')->auszuege[0]->umsaetze[2]);

        self::assertSame('Förderverein Sportfreunde', $d->sepa('ABWA'));
        self::assertSame('Weiß, Anna-Lena', $d->name);
    }

    public function testAReturnedDirectDebitIsAReversalWithItsEffectOnTheBalance(): void
    {
        $umsatz = self::lies('sparkasse-053-001-02.xml')->auszuege[1]->umsaetze[0];
        $d = self::details($umsatz);

        self::assertTrue($umsatz->storno);
        self::assertSame(4590, $umsatz->cent, 'CRDT with RvslInd: the money comes back.');
        self::assertSame('Getränke Müller GmbH & Co. KG Musterstadt', $d->name, 'The counterparty of the original debit.');
        self::assertSame('DE40120505550001234567', $d->iban);
    }

    public function testSeveralPurposeElementsAreJoinedAndAFeeHasNoCounterparty(): void
    {
        $d = self::details(self::lies('sparkasse-053-001-02.xml')->auszuege[1]->umsaetze[1]);

        self::assertSame('Entgelt Kontoführung Abrechnung 03/2024', $d->verwendungszweck);
        self::assertNull($d->name);
        self::assertNull($d->iban);
        self::assertSame('805', $d->gvc);
    }

    public function testTheSameStatementsAsMt940GiveTheSameBookings(): void
    {
        $camt = self::lies('sparkasse-053-001-02.xml')->auszuege;
        $mt940 = new Mt940Parser()->parse((string) file_get_contents(__DIR__ . '/../../fixtures/mt940/sparkasse.sta'));

        $kurz = static fn(Umsatz $u): array => [
            self::datum($u->buchungsdatum),
            $u->cent,
            $u->storno,
            $u->details?->verwendungszweck,
            $u->details?->iban,
            $u->details?->gvc,
        ];
        self::assertSame(
            array_map($kurz, [...$mt940[0]->umsaetze, ...$mt940[1]->umsaetze]),
            array_map($kurz, [...$camt[0]->umsaetze, ...$camt[1]->umsaetze]),
        );
    }

    public function testVersion08WithStatusCodePartyAndBicfi(): void
    {
        $ergebnis = self::lies('vrbank-053-001-08.xml');

        self::assertCount(1, $ergebnis->auszuege);
        $auszug = $ergebnis->auszuege[0];
        self::assertSame('DE93876543210007654321', $auszug->konto->iban);
        self::assertSame('1', $auszug->auszugsnummer);
        self::assertSame('2023-12-29', self::datum($auszug->anfangssaldo->datum));
        self::assertSame(81205, $auszug->anfangssaldo->cent);
        self::assertSame(82206, $auszug->schlusssaldo->cent);
        self::assertTrue($auszug->saldoStimmt());

        // The pending entry is skipped and counted, the balance knows nothing of it.
        self::assertSame(1, $ergebnis->vorgemerkt);
        self::assertSame([7500, -1999, -7500, -3000, 5000, 1000], array_map(static fn(Umsatz $u): int => $u->cent, $auszug->umsaetze));

        [$beitrag, $lastschrift] = $auszug->umsaetze;
        self::assertSame('2023-12-29', self::datum($beitrag->buchungsdatum), 'A date-time is read as its day in Europe/Berlin.');
        self::assertSame('VR-0001', $beitrag->bankreferenz, 'The servicer reference of the transaction when the entry has none.');
        self::assertSame('Mustermann, Erika', self::details($beitrag)->name);
        self::assertSame('GENODED1XXX', self::details($beitrag)->bic);

        self::assertSame('2024-01-02', self::datum($lastschrift->buchungsdatum));
        self::assertSame('2023-12-29', self::datum($lastschrift->valuta));
        $d = self::details($lastschrift);
        self::assertSame('Telefonanbieter Muster AG', $d->name);
        self::assertSame('DEUTDEFFXXX', $d->bic);
        self::assertSame([
            'ABWE' => 'Sportheim Förderverein',
            'CRED' => 'DE11ZZZ00000000001',
            'EREF' => 'TK-2023-12-998877',
            'MREF' => 'TEL-000123',
            'SVWZ' => 'Telefon Vereinsheim 12/2023 Kundennr. 0815',
        ], $d->sepa);
    }

    public function testAReturnedCreditTransferIsAReversalNamingTheOriginalDebtor(): void
    {
        $umsatz = self::lies('vrbank-053-001-08.xml')->auszuege[0]->umsaetze[2];

        self::assertTrue($umsatz->storno);
        self::assertSame(-7500, $umsatz->cent);
        self::assertSame('Mustermann, Erika', self::details($umsatz)->name);
        self::assertSame('DE36120700000002222222', self::details($umsatz)->iban);
        self::assertSame('159', self::details($umsatz)->gvc);
    }

    public function testABatchIsOneBookingWithEveryPurposeButNoCounterparty(): void
    {
        $umsatz = self::lies('vrbank-053-001-08.xml')->auszuege[0]->umsaetze[3];
        $d = self::details($umsatz);

        self::assertSame(-3000, $umsatz->cent);
        self::assertSame('Startgeld Turnier A / Startgeld Turnier B', $d->verwendungszweck);
        self::assertNull($d->name);
        self::assertNull($d->iban);
        self::assertSame('NONREF', $umsatz->kundenreferenz);
        self::assertSame(['SVWZ' => 'Startgeld Turnier A / Startgeld Turnier B'], $d->sepa);
        self::assertSame('SAMMEL-UEBERWEISUNG', $d->buchungstext);
    }

    public function testAStructuredCreditorReferenceIsThePurposeWhenThereIsNoText(): void
    {
        self::assertSame('RF18539007547034', self::details(self::lies('vrbank-053-001-08.xml')->auszuege[0]->umsaetze[4])->verwendungszweck);
    }

    public function testAFullPurposeElementContinuesWithoutASpace(): void
    {
        $zweck = self::details(self::lies('vrbank-053-001-08.xml')->auszuege[0]->umsaetze[5])->verwendungszweck;

        self::assertStringStartsWith('Mitgliedsbeitraege 2024 Familie Mustermann', $zweck);
        self::assertStringEndsWith('NrXXEnde', $zweck);
        self::assertSame(144, mb_strlen($zweck));
    }

    public function testAZipWithDailyStatementsIsReadInDateOrderAndIgnoresOtherFiles(): void
    {
        $ergebnis = self::lies('tagesauszuege-053-001-04.zip');

        self::assertCount(2, $ergebnis->auszuege, 'The text file and the macOS metadata are no statements.');
        [$erster, $zweiter] = $ergebnis->auszuege;
        self::assertSame(['TAG-20240402', 'TAG-20240403'], [$erster->referenz, $zweiter->referenz], 'Stored newest first, read oldest first.');
        self::assertSame(-2000, $erster->anfangssaldo->cent, 'A debit balance is negative.');
        self::assertSame(8000, $erster->schlusssaldo->cent);
        self::assertSame($erster->schlusssaldo->cent, $zweiter->anfangssaldo->cent);
        self::assertSame(5550, $zweiter->schlusssaldo->cent);
        self::assertSame([10000], array_map(static fn(Umsatz $u): int => $u->cent, $erster->umsaetze));
        self::assertSame([-2450], array_map(static fn(Umsatz $u): int => $u->cent, $zweiter->umsaetze));
        self::assertSame('Zuschuss Übungsleiter Q1 2024', self::details($erster->umsaetze[0])->verwendungszweck);
    }

    public function testTheSameStatementTwiceInAZipIsReadOnceWithItsPendingEntries(): void
    {
        $xml = self::inhalt('sparkasse-053-001-02.xml');
        $ergebnis = new Camt053Parser()->parse(self::zip(['a.xml' => $xml, 'b/kopie.xml' => $xml]));
        self::assertCount(2, $ergebnis->auszuege);

        $xml = self::inhalt('vrbank-053-001-08.xml');
        $ergebnis = new Camt053Parser()->parse(self::zip(['a.xml' => $xml, 'b/kopie.xml' => $xml]));
        self::assertCount(1, $ergebnis->auszuege);
        self::assertSame(1, $ergebnis->vorgemerkt);
    }

    public function testStatementsOfOneDayFollowTheirSequenceNumber(): void
    {
        $tag = static fn(string $nr, string $anfang, string $schluss, string $betrag): string => str_replace(
            '<Id>T1</Id>',
            '<Id>T' . $nr . '</Id><ElctrncSeqNb>' . $nr . '</ElctrncSeqNb>',
            self::stmt(self::bal('PRCD', $anfang, '2024-05-02') . self::bal('CLBD', $schluss, '2024-05-02'), self::ntry($betrag, 'CRDT', '2024-05-02')),
        );
        $ergebnis = new Camt053Parser()->parse(self::zip(['spaet.xml' => $tag('10', '3.00', '6.00', '3.00'), 'frueh.xml' => $tag('9', '1.00', '3.00', '2.00')]));

        self::assertSame(['9', '10'], array_map(static fn(Kontoauszug $a): ?string => $a->auszugsnummer, $ergebnis->auszuege));
    }

    public function testPurposeElementsAreJoinedByTheirTrimmedLength(): void
    {
        $voll = str_pad('Beitrag', 139, '.') . ' ';
        $tx = static fn(string ...$ustrd): string => self::stmt(
            self::bal('PRCD', '0', '2024-05-01') . self::bal('CLBD', '1.00', '2024-05-01'),
            str_replace('</BookgDt>', '</BookgDt><NtryDtls><TxDtls><RmtInf>' . implode('', array_map(static fn(string $u): string => '<Ustrd>' . $u . '</Ustrd>', $ustrd)) . '</RmtInf></TxDtls></NtryDtls>', self::ntry('1.00', 'CRDT', '2024-05-01')),
        );
        $zweck = static fn(string $xml): string => (string) new Camt053Parser()->parse($xml)->auszuege[0]->umsaetze[0]->details?->verwendungszweck;

        self::assertSame(rtrim($voll) . ' 4711 Danke', $zweck($tx($voll, '4711 Danke')), 'A full element ending in a space ended on a word.');
        self::assertSame('Beitrag 2024 Danke', $zweck($tx("\n" . str_repeat(' ', 140) . 'Beitrag 2024' . "\n    ", 'Danke')), 'Indentation is no text.');
    }

    public function testADateTimeIsReadInBerlinWhateverZoneTheProcessRunsIn(): void
    {
        $xml = self::stmt(
            self::bal('PRCD', '0', '2023-12-31') . self::bal('CLBD', '1.00', '2024-01-01'),
            '<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><DtTm>2023-12-31T23:30:00Z</DtTm></BookgDt></Ntry>',
        );
        $vorher = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $umsatz = new Camt053Parser()->parse($xml)->auszuege[0]->umsaetze[0];
        } finally {
            date_default_timezone_set($vorher);
        }

        self::assertSame('2024-01-01', self::datum($umsatz->buchungsdatum));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namensraeume(): iterable
    {
        foreach (['02', '04', '06', '08', '10'] as $version) {
            yield 'camt.053.001.' . $version => ['urn:iso:std:iso:20022:tech:xsd:camt.053.001.' . $version];
        }
        yield 'unknown namespace' => ['urn:example:bank:statement'];
    }

    #[DataProvider('namensraeume')]
    public function testEveryVersionIsReadRegardlessOfItsNamespace(string $namensraum): void
    {
        foreach (['sparkasse-053-001-02.xml', 'vrbank-053-001-08.xml'] as $datei) {
            $xml = (string) preg_replace('/xmlns="[^"]*"/', 'xmlns="' . $namensraum . '"', self::inhalt($datei), 1);
            $ergebnis = new Camt053Parser()->parse($xml);
            self::assertEquals(self::lies($datei), $ergebnis, $datei);
        }
    }

    public function testWithoutNamespaceAndWithPrefixesItIsReadAlike(): void
    {
        $xml = self::inhalt('vrbank-053-001-08.xml');
        $ohne = (string) preg_replace('/ xmlns="[^"]*"/', '', $xml, 1);
        $praefix = (string) preg_replace(['/<(\/?)([A-Za-z])/', '/ xmlns="/'], ['<$1c:$2', ' xmlns:c="'], $xml);

        self::assertEquals(self::lies('vrbank-053-001-08.xml'), new Camt053Parser()->parse($ohne));
        self::assertEquals(self::lies('vrbank-053-001-08.xml'), new Camt053Parser()->parse($praefix));
    }

    public function testBalanceTypesWithoutOpeningOrClosingBooked(): void
    {
        // Two interim balances: the first opens, the last closes - as interim.
        $auszug = new Camt053Parser()->parse(self::stmt(
            self::bal('ITBD', '10.00', '2024-05-01') . self::bal('ITBD', '15.00', '2024-05-02'),
            self::ntry('5.00', 'CRDT', '2024-05-02'),
        ))->auszuege[0];
        self::assertSame([1000, 1500], [$auszug->anfangssaldo->cent, $auszug->schlusssaldo->cent]);
        self::assertTrue($auszug->anfangssaldo->zwischensaldo);
        self::assertTrue($auszug->schlusssaldo->zwischensaldo);
        self::assertTrue($auszug->saldoStimmt());

        // OPBD wins over PRCD; balances of other types are ignored.
        $auszug = new Camt053Parser()->parse(self::stmt(
            self::bal('PRCD', '1.00', '2024-04-30') . self::bal('OPBD', '10.00', '2024-05-01') . self::bal('CLAV', '99.00', '2024-05-01') . self::bal('CLBD', '7.50', '2024-05-01'),
            self::ntry('2.50', 'DBIT', '2024-05-01'),
        ))->auszuege[0];
        self::assertSame([1000, 750], [$auszug->anfangssaldo->cent, $auszug->schlusssaldo->cent]);
        self::assertFalse($auszug->anfangssaldo->zwischensaldo);
        self::assertTrue($auszug->saldoStimmt());
    }

    public function testAmountsDatesAndStatusVariants(): void
    {
        $auszug = new Camt053Parser()->parse(self::stmt(
            self::bal('PRCD', '0', '2024-05-01') . self::bal('CLBD', '1000.10', '2024-05-02'),
            self::ntry('1000.100', 'CRDT', '2024-05-02')
            . '<Ntry><Amt Ccy="EUR">9.99</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>INFO</Sts><BookgDt><Dt>2024-05-02</Dt></BookgDt></Ntry>'
            . '<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts><Prtry>PDNG</Prtry></Sts><BookgDt><Dt>2024-05-02</Dt></BookgDt></Ntry>'
            // Late in the evening UTC is already the next day in Berlin.
            . '<Ntry><Amt Ccy="EUR">0.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><DtTm>2024-05-01T22:30:00Z</DtTm></BookgDt><ValDt><Dt>2024-05-01</Dt></ValDt><AddtlNtryInf>Abschluss</AddtlNtryInf></Ntry>',
        ));

        self::assertSame(2, $auszug->vorgemerkt);
        $umsaetze = $auszug->auszuege[0]->umsaetze;
        self::assertSame([100010, 0], array_map(static fn(Umsatz $u): int => $u->cent, $umsaetze));
        self::assertSame('2024-05-02', self::datum($umsaetze[1]->buchungsdatum));
        self::assertSame('2024-05-01', self::datum($umsaetze[1]->valuta));
        $d = self::details($umsaetze[1]);
        self::assertSame('Abschluss', $d->verwendungszweck, 'Without remittance information the entry text is the purpose.');
        self::assertSame([], $d->sepa);
        self::assertSame('', $umsaetze[1]->buchungsschluessel);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kaputt(): iterable
    {
        $ok = self::bal('PRCD', '1.00', '2024-05-01') . self::bal('CLBD', '1.00', '2024-05-01');
        yield 'empty' => ['', 'keine lesbare XML-Datei'];
        yield 'no XML' => ['Datum;Betrag 99999', 'keine lesbare XML-Datei'];
        yield 'broken XML' => ['<Document><BkToCstmrStmt><Stmt>Geheim', 'keine lesbare XML-Datei'];
        yield 'doctype in UTF-16' => [(string) mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE Document [<!ENTITY g "Geheim">]><Document><BkToCstmrStmt><Stmt>&g;</Stmt></BkToCstmrStmt></Document>', 'UTF-16LE', 'UTF-8'), 'keine lesbare XML-Datei'];
        yield 'second statement of a file' => [str_replace('</Stmt></BkToCstmrStmt>', '</Stmt><Stmt><Id>T2</Id><Acct><Id><IBAN>DE41500105170123456789</IBAN></Id></Acct>' . self::bal('PRCD', '1.00', '2024-05-01') . '</Stmt></BkToCstmrStmt>', self::stmt($ok, '')), 'Auszug 2: Der Schlusssaldo (CLBD) fehlt.'];
        yield 'statement numbers count per file in a zip' => [self::zip(['a.xml' => self::stmt($ok, ''), 'liesmich.txt' => 'x', 'b.xml' => self::stmt(self::bal('PRCD', '1.00', '2024-05-01'), '')]), 'XML-Datei 2 im ZIP: Auszug 1: Der Schlusssaldo (CLBD) fehlt.'];
        yield 'doctype' => ['<?xml version="1.0"?><!DOCTYPE x [<!ENTITY g "Geheim">]><Document><BkToCstmrStmt/></Document>', 'keine lesbare XML-Datei'];
        yield 'camt.052' => ['<Document><BkToCstmrAcctRpt><Rpt/></BkToCstmrAcctRpt></Document>', 'CAMT.052'];
        yield 'camt.054' => ['<Document><BkToCstmrDbtCdtNtfctn/></Document>', 'CAMT.054'];
        yield 'other XML' => ['<Invoice>Geheim</Invoice>', 'kein Kontoauszug im Format CAMT.053'];
        yield 'no statement' => ['<Document><BkToCstmrStmt><GrpHdr/></BkToCstmrStmt></Document>', 'keinen Kontoauszug (Stmt)'];
        yield 'no account' => ['<Document><BkToCstmrStmt><Stmt><Id>1</Id>' . $ok . '</Stmt></BkToCstmrStmt></Document>', 'Auszug 1: Das Konto (Acct/Id) fehlt.'];
        yield 'no opening balance' => [self::stmt(self::bal('CLBD', '1.00', '2024-05-01'), ''), 'Auszug 1: Der Anfangssaldo (OPBD/PRCD) fehlt.'];
        yield 'no closing balance' => [self::stmt(self::bal('PRCD', '1.00', '2024-05-01'), ''), 'Auszug 1: Der Schlusssaldo (CLBD) fehlt.'];
        yield 'comma amount' => [self::stmt($ok, self::ntry('99999,99', 'CRDT', '2024-05-01')), 'Auszug 1, Buchung 1: Der Betrag (Amt) ist ungültig.'];
        yield 'three decimals' => [self::stmt($ok, self::ntry('1.999', 'CRDT', '2024-05-01')), 'Auszug 1, Buchung 1: Der Betrag (Amt) ist ungültig.'];
        yield 'negative amount' => [self::stmt($ok, self::ntry('-5.00', 'CRDT', '2024-05-01')), 'Auszug 1, Buchung 1: Der Betrag (Amt) ist ungültig.'];
        yield 'no direction' => [self::stmt($ok, self::ntry('5.00', 'Geheim', '2024-05-01')), 'Auszug 1, Buchung 1: Soll/Haben (CdtDbtInd) fehlt.'];
        yield 'impossible date' => [self::stmt($ok, self::ntry('5.00', 'CRDT', '2024-02-30')), 'Auszug 1, Buchung 1: Das Datum (BookgDt) ist ungültig.'];
        yield 'no booking date' => [self::stmt($ok, '<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts></Ntry>'), 'Auszug 1, Buchung 1: Das Buchungsdatum (BookgDt) fehlt.'];
        yield 'no currency' => [self::stmt($ok, '<Ntry><Amt>1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2024-05-01</Dt></BookgDt></Ntry>'), 'Auszug 1, Buchung 1: Die Währung (Ccy) fehlt.'];
        yield 'zip without XML' => [self::zip(['liesmich.txt' => 'Geheim']), 'Das ZIP enthält keine XML-Datei.'];
        yield 'broken file in zip' => [self::zip(['a.xml' => self::stmt($ok, ''), 'b.xml' => 'Geheim 99999']), 'XML-Datei 2 im ZIP: Die Datei ist keine lesbare XML-Datei.'];
    }

    #[DataProvider('kaputt')]
    public function testUnreadableFilesAreRefusedWithoutQuotingThem(string $inhalt, string $meldung): void
    {
        try {
            new Camt053Parser()->parse($inhalt);
            self::fail('Camt053Exception expected');
        } catch (Camt053Exception $e) {
            self::assertStringContainsString($meldung, $e->getMessage());
            self::assertStringNotContainsString('99999', $e->getMessage());
            self::assertStringNotContainsString('Geheim', $e->getMessage());
            self::assertStringNotContainsString('DE41500105170123456789', $e->getMessage());
        }
    }

    public function testAZipThatUnpacksTooLargeIsRefused(): void
    {
        $gross = self::stmt(self::bal('PRCD', '1.00', '2024-05-01') . self::bal('CLBD', '1.00', '2024-05-01'), '') . str_repeat(' ', Camt053Parser::MAX_XML_BYTES);

        $this->expectException(Camt053Exception::class);
        $this->expectExceptionMessage('zu groß');
        new Camt053Parser()->parse(self::zip(['gross.xml' => $gross]));
    }

    public function testFormatHints(): void
    {
        self::assertTrue(Camt053Parser::istZip(self::inhalt('tagesauszuege-053-001-04.zip')));
        self::assertFalse(Camt053Parser::istZip(self::inhalt('vrbank-053-001-08.xml')));
        self::assertTrue(Camt053Parser::istXml(self::inhalt('vrbank-053-001-08.xml')));
        self::assertTrue(Camt053Parser::istXml("\xEF\xBB\xBF\r\n  <Document/>"));
        self::assertFalse(Camt053Parser::istXml(':20:STARTUMSE'));
        self::assertFalse(Camt053Parser::istXml('Buchungstag;Betrag'));
    }

    private static function stmt(string $salden, string $eintraege): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt><Id>T1</Id>'
            . '<Acct><Id><IBAN>DE41500105170123456789</IBAN></Id></Acct>' . $salden . $eintraege . '</Stmt></BkToCstmrStmt></Document>';
    }

    private static function bal(string $art, string $betrag, string $datum): string
    {
        return '<Bal><Tp><CdOrPrtry><Cd>' . $art . '</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">' . $betrag . '</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>' . $datum . '</Dt></Dt></Bal>';
    }

    private static function ntry(string $betrag, string $richtung, string $datum): string
    {
        return '<Ntry><Amt Ccy="EUR">' . $betrag . '</Amt><CdtDbtInd>' . $richtung . '</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><Dt>' . $datum . '</Dt></BookgDt></Ntry>';
    }

    /**
     * A ZIP with the files stored uncompressed - written by hand, so the
     * tests need no ext-zip.
     *
     * @param array<string, string> $dateien
     */
    public static function zip(array $dateien): string
    {
        $lokal = '';
        $zentral = '';
        foreach ($dateien as $name => $inhalt) {
            $crc = crc32($inhalt);
            $groesse = strlen($inhalt);
            $zentral .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $groesse, $groesse, strlen($name), 0, 0, 0, 0, 0, strlen($lokal)) . $name;
            $lokal .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $groesse, $groesse, strlen($name), 0) . $name . $inhalt;
        }

        return $lokal . $zentral . pack('VvvvvVVv', 0x06054B50, 0, 0, count($dateien), count($dateien), strlen($zentral), strlen($lokal), 0);
    }
}
