<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\Kontoauszug;
use App\Service\Bank\Mt940Exception;
use App\Service\Bank\Mt940Parser;
use App\Service\Bank\Umsatz;
use App\Service\Bank\Umsatzdetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MT940 import (issue #60/M9-2, docs/spec/04-bank-und-abgleich.md
 * "Pflicht-Tests": mehrere Auszüge, Fortsetzungszeilen, Jahreswechsel im
 * :61:, RC/RD-Storno, strukturierte/unstrukturierte :86:, SEPA-Schlüssel,
 * Encoding, Saldenprüfung ok/abweichend, Sparkasse- und VR-Bank-Fixtures).
 *
 * The fixtures are synthetic files in the layout of the two banks, see
 * tests/fixtures/mt940/README.md.
 */
final class Mt940ParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/mt940/';

    /**
     * @return list<Kontoauszug>
     */
    private static function lies(string $datei): array
    {
        return new Mt940Parser()->parse((string) file_get_contents(self::FIXTURES . $datei));
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

    public function testFixturesKeepTheBanksEncodingAndLineEndings(): void
    {
        foreach (['sparkasse.sta', 'vrbank.sta'] as $datei) {
            $inhalt = (string) file_get_contents(self::FIXTURES . $datei);
            self::assertFalse(mb_check_encoding($inhalt, 'UTF-8'), $datei . ' must stay Windows-1252');
            self::assertStringContainsString("\r\n", $inhalt, $datei . ' must keep CRLF');
        }
    }

    public function testSparkasseFileWithTwoStatements(): void
    {
        $auszuege = self::lies('sparkasse.sta');

        self::assertCount(2, $auszuege);
        [$erster, $zweiter] = $auszuege;

        self::assertSame('STARTUMSE', $erster->referenz);
        self::assertSame('12345678', $erster->konto->blz);
        self::assertSame('0001234567', $erster->konto->kontonummer);
        self::assertNull($erster->konto->iban);
        self::assertSame('00012/001', $erster->auszugsnummer);
        self::assertSame('00013/001', $zweiter->auszugsnummer);

        self::assertSame('2024-03-11', self::datum($erster->anfangssaldo->datum));
        self::assertSame(152340, $erster->anfangssaldo->cent);
        self::assertSame('EUR', $erster->anfangssaldo->waehrung);
        self::assertFalse($erster->anfangssaldo->zwischensaldo);
        self::assertSame(160750, $erster->schlusssaldo->cent);
        self::assertSame([-4590, 25000, -12000], array_map(static fn (Umsatz $u): int => $u->cent, $erster->umsaetze));
        self::assertSame([4590, -350], array_map(static fn (Umsatz $u): int => $u->cent, $zweiter->umsaetze));

        self::assertTrue($erster->saldoStimmt());
        self::assertTrue($zweiter->saldoStimmt());
        self::assertSame(0, $zweiter->saldoDifferenzCent());
    }

    public function testSparkasseStructuredDetailsAcrossContinuationLines(): void
    {
        $lastschrift = self::lies('sparkasse.sta')[0]->umsaetze[0];

        self::assertSame('2024-03-11', self::datum($lastschrift->valuta));
        self::assertSame('2024-03-11', self::datum($lastschrift->buchungsdatum));
        self::assertFalse($lastschrift->storno);
        self::assertSame('N005', $lastschrift->buchungsschluessel);
        self::assertSame('NONREF', $lastschrift->kundenreferenz);
        self::assertNull($lastschrift->bankreferenz);

        $details = self::details($lastschrift);
        self::assertTrue($details->strukturiert);
        self::assertSame('105', $details->gvc);
        self::assertSame('FOLGELASTSCHRIFT', $details->buchungstext);
        self::assertSame('931', $details->primanota);
        self::assertSame('Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234', $details->verwendungszweck);
        self::assertSame([
            'EREF' => 'RE-2024-0311-77',
            'MREF' => 'M-0815',
            'CRED' => 'DE98ZZZ09999999999',
            'SVWZ' => 'Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234',
        ], $details->sepa);
        self::assertSame('RE-2024-0311-77', $details->sepa('EREF'));
        self::assertNull($details->sepa('KREF'));
        self::assertSame('GENODEF1XXX', $details->bic);
        self::assertSame('DE40120505550001234567', $details->iban);
        self::assertSame('Getränke Müller GmbH & Co. KG Musterstadt', $details->name);
        self::assertSame('992', $details->textschluesselergaenzung);
        self::assertSame(
            'EREF+RE-2024-0311-77 MREF+M-0815 CRED+DE98ZZZ09999999999 SVWZ+Getränke Müller Rechnung 4711 Vereinsheim Kd-Nr 1234',
            $details->verwendungszweckRoh,
        );
    }

    public function testPurposeAndNameContinuedAcrossSubfieldsAndLines(): void
    {
        [, $spende, $pauschale] = self::lies('sparkasse.sta')[0]->umsaetze;

        // "?21SVWZ+Spende Jugendabteilung" + "?22 Sommerfest 2024"
        self::assertSame('Spende Jugendabteilung Sommerfest 2024', self::details($spende)->verwendungszweck);
        self::assertSame('NOTPROVIDED', self::details($spende)->sepa('EREF'));
        self::assertSame('Schäfer, Jürgen', self::details($spende)->name);

        // SVWZ+ spans two subfields, ABWA+ is cut by a line break, the
        // name continues on a line starting with a space.
        $details = self::details($pauschale);
        self::assertSame('Übungsleiterpauschale März 2024 Turnen Kinder', $details->verwendungszweck);
        self::assertSame('Förderverein Sportfreunde', $details->sepa('ABWA'));
        self::assertSame('Weiß, Anna-Lena', $details->name);
        self::assertNull($details->textschluesselergaenzung);
    }

    public function testReturnedDebitIsAReversalThatRaisesTheBalance(): void
    {
        $ruecklastschrift = self::lies('sparkasse.sta')[1]->umsaetze[0];

        self::assertTrue($ruecklastschrift->storno);
        self::assertSame(4590, $ruecklastschrift->cent);
        self::assertSame('Rücklastschrift Getränke Müller Rechnung 4711', self::details($ruecklastschrift)->verwendungszweck);
        self::assertSame('RE-2024-0311-77', self::details($ruecklastschrift)->sepa('EREF'));
    }

    public function testSeveralPurposeLinesWithoutSepaKeys(): void
    {
        $entgelt = self::lies('sparkasse.sta')[1]->umsaetze[1];
        $details = self::details($entgelt);

        self::assertSame([], $details->sepa);
        self::assertSame('Entgelt Kontoführung Abrechnung 03/2024', $details->verwendungszweck);
        self::assertSame($details->verwendungszweck, $details->verwendungszweckRoh);
        self::assertNull($details->name);
        self::assertNull($details->bic);
    }

    public function testVrBankFileAcrossNewYear(): void
    {
        $auszuege = self::lies('vrbank.sta');

        self::assertCount(1, $auszuege);
        $auszug = $auszuege[0];
        self::assertSame('DE93876543210007654321', $auszug->konto->iban);
        self::assertNull($auszug->konto->blz);
        self::assertSame('2023-12-29', self::datum($auszug->anfangssaldo->datum));
        self::assertSame('2024-01-02', self::datum($auszug->schlusssaldo->datum));
        self::assertSame([7500, -1999, -995, -7500], array_map(static fn (Umsatz $u): int => $u->cent, $auszug->umsaetze));
        self::assertTrue($auszug->saldoStimmt());

        [$beitrag, $telefon, $karte] = $auszug->umsaetze;

        self::assertSame('2023-12-29', self::datum($beitrag->valuta));
        self::assertSame('2023-12-29', self::datum($beitrag->buchungsdatum));

        // Value date in the new year, booked in the old one.
        self::assertSame('2024-01-02', self::datum($telefon->valuta));
        self::assertSame('2023-12-29', self::datum($telefon->buchungsdatum));

        // Value date in the old year, booked in the new one.
        self::assertSame('2023-12-31', self::datum($karte->valuta));
        self::assertSame('2024-01-02', self::datum($karte->buchungsdatum));
    }

    public function testVrBankSubfieldsOnTheirOwnLines(): void
    {
        [$beitrag, $telefon] = self::lies('vrbank.sta')[0]->umsaetze;

        self::assertSame('Mitgliedsbeitrag 2024 Max Mustermann Jugend U13', self::details($beitrag)->verwendungszweck);
        self::assertSame('0599', self::details($beitrag)->primanota);
        self::assertSame('Mustermann, Erika', self::details($beitrag)->name);

        self::assertSame('NDDT', $telefon->buchungsschluessel);
        self::assertSame('KREF-4711', $telefon->kundenreferenz);
        self::assertSame('0815', $telefon->bankreferenz);

        $details = self::details($telefon);
        self::assertSame('BASISLASTSCHRIFT', $details->buchungstext);
        self::assertSame('Telefon Vereinsheim 12/2023 Kundennr. 0815', $details->verwendungszweck);
        self::assertSame('TK-2023-12-998877', $details->sepa('EREF'));
        self::assertSame('TEL-000123', $details->sepa('MREF'));
        self::assertSame('DE11ZZZ00000000001', $details->sepa('CRED'));
        self::assertSame('Sportheim Förderverein', $details->sepa('ABWE'));
        self::assertSame('DE54120500000003333333', $details->iban);
    }

    public function testUnstructuredDetailsAreTakenAsPlainText(): void
    {
        $karte = self::lies('vrbank.sta')[0]->umsaetze[2];

        self::assertSame('/OCMT/EUR9,95/', $karte->zusatz);
        $details = self::details($karte);
        self::assertFalse($details->strukturiert);
        self::assertNull($details->gvc);
        self::assertSame([], $details->sepa);
        self::assertSame('Kartenzahlung Sporthaus Größe 42 Trikots Jugend', $details->verwendungszweck);
    }

    public function testReversedCreditLowersTheBalance(): void
    {
        $rueckgabe = self::lies('vrbank.sta')[0]->umsaetze[3];

        self::assertTrue($rueckgabe->storno);
        self::assertSame(-7500, $rueckgabe->cent);
        self::assertSame('159', self::details($rueckgabe)->gvc);
        self::assertSame('Rückgabe Mitgliedsbeitrag 2024 doppelt gezahlt', self::details($rueckgabe)->verwendungszweck);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodings(): iterable
    {
        yield 'Windows-1252 as delivered' => [''];
        yield 'UTF-8' => ['utf8'];
        yield 'UTF-8 with BOM' => ['bom'];
        yield 'LF only' => ['lf'];
    }

    #[DataProvider('encodings')]
    public function testEncodingsGiveTheSameResult(string $variante): void
    {
        $original = (string) file_get_contents(self::FIXTURES . 'sparkasse.sta');
        $inhalt = match ($variante) {
            '' => $original,
            'utf8' => mb_convert_encoding($original, 'UTF-8', 'Windows-1252'),
            'bom' => "\xEF\xBB\xBF" . mb_convert_encoding($original, 'UTF-8', 'Windows-1252'),
            'lf' => str_replace("\r\n", "\n", $original),
        };

        $auszuege = new Mt940Parser()->parse($inhalt);

        self::assertEquals(self::lies('sparkasse.sta'), $auszuege);
        self::assertSame('Getränke Müller GmbH & Co. KG Musterstadt', self::details($auszuege[0]->umsaetze[0])->name);
    }

    public function testBalanceMismatchIsReportedNotRefused(): void
    {
        $inhalt = str_replace(':62F:C240311EUR1607,50', ':62F:C240311EUR1600,00', (string) file_get_contents(self::FIXTURES . 'sparkasse.sta'));

        $auszuege = new Mt940Parser()->parse($inhalt);

        self::assertFalse($auszuege[0]->saldoStimmt());
        self::assertSame(750, $auszuege[0]->saldoDifferenzCent());
        self::assertTrue($auszuege[1]->saldoStimmt());
    }

    public function testCurrencyMismatchFailsTheBalanceCheck(): void
    {
        $auszug = new Mt940Parser()->parse(self::mt940([':60F:C240102EUR10,00', ':62F:C240102CHF10,00']))[0];

        self::assertSame(0, $auszug->saldoDifferenzCent());
        self::assertFalse($auszug->saldoStimmt());
    }

    /**
     * @param list<string> $felder lines after :20:/:25:
     */
    private static function mt940(array $felder, string $konto = '12345678/0001234567'): string
    {
        return implode("\n", [':20:TEST', ':25:' . $konto, ...$felder, '-']) . "\n";
    }

    public function testIntermediateAndDebitBalances(): void
    {
        $auszug = new Mt940Parser()->parse(self::mt940([
            ':60M:D240102EUR100,5',
            ':61:240102D0,5NMSCNONREF',
            ':62M:D240102EUR101,',
        ]))[0];

        self::assertTrue($auszug->anfangssaldo->zwischensaldo);
        self::assertTrue($auszug->schlusssaldo->zwischensaldo);
        self::assertSame(-10050, $auszug->anfangssaldo->cent);
        self::assertSame(-10100, $auszug->schlusssaldo->cent);
        self::assertSame(-50, $auszug->umsaetze[0]->cent);
        self::assertSame('2024-01-02', self::datum($auszug->umsaetze[0]->buchungsdatum), 'without a booking date the value date is used');
        self::assertNull($auszug->umsaetze[0]->details, ':61: without :86:');
        self::assertTrue($auszug->saldoStimmt());
    }

    public function testSwiftHeadersUnknownFieldsAndStatementInfoAreSkipped(): void
    {
        $inhalt = "{1:F01TESTDEFFAXXX0000000000}{2:I940TESTDEFFXXXXN}{4:\r\n"
            . ":20:TEST\r\n:21:NONREF\r\n:25:12345678/0001234567\r\n:28C:1\r\n"
            . ":60F:C240102EUR10,00\r\n"
            . ":61:2401020102CR5,00NTRFNONREF\r\n:86:166?00GUTSCHRIFT?20Danke\r\n"
            . ":NS:22Sparkasse intern\r\n"
            . ":62F:C240102EUR15,00\r\n:64:C240102EUR15,00\r\n:86:Information zum Auszug\r\n-}\r\n";

        $auszug = new Mt940Parser()->parse($inhalt)[0];

        self::assertCount(1, $auszug->umsaetze);
        self::assertSame('Danke', self::details($auszug->umsaetze[0])->verwendungszweck);
        self::assertTrue($auszug->saldoStimmt());
    }

    public function testOldAtAtLineSeparator(): void
    {
        $inhalt = ':20:TEST@@:25:12345678/0001234567@@:60F:C240102EUR1,00@@:61:2401020102CR1,00NTRFNONREF@@:62F:C240102EUR2,00@@-@@';

        $auszug = new Mt940Parser()->parse($inhalt)[0];

        self::assertSame(100, $auszug->umsaetze[0]->cent);
        self::assertTrue($auszug->saldoStimmt());
    }

    public function testDayPastMonthEndBecomesTheLastDay(): void
    {
        $umsatz = new Mt940Parser()->parse(self::mt940([
            ':60F:C240229EUR0,00',
            ':61:2402300229CR0,12NMSCNONREF',
            ':62F:C240229EUR0,12',
        ]))[0]->umsaetze[0];

        self::assertSame('2024-02-29', self::datum($umsatz->valuta));
        self::assertSame('2024-02-29', self::datum($umsatz->buchungsdatum));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, ?string}>
     */
    public static function konten(): iterable
    {
        yield 'IBAN' => ['DE93876543210007654321', 'DE93876543210007654321', null, null];
        yield 'IBAN with currency' => ['DE93876543210007654321EUR', 'DE93876543210007654321', null, null];
        yield 'BLZ/account' => ['12345678/0001234567', null, '12345678', '0001234567'];
        yield 'BLZ/account with currency' => ['12345678/1234567EUR', null, '12345678', '1234567'];
        yield 'BIC/account' => ['TESTDEFFXXX/1234567', null, 'TESTDEFFXXX', '1234567'];
        yield 'IBAN with a wrong check digit' => ['DE00876543210007654321', null, null, null];
        yield 'unknown' => ['Vereinskonto', null, null, null];
    }

    #[DataProvider('konten')]
    public function testAccountIdentification(string $roh, ?string $iban, ?string $blz, ?string $kontonummer): void
    {
        $konto = new Mt940Parser()->parse(self::mt940([':60F:C240102EUR0,00', ':62F:C240102EUR0,00'], $roh))[0]->konto;

        self::assertSame($roh, $konto->roh);
        self::assertSame($iban, $konto->iban);
        self::assertSame($blz, $konto->blz);
        self::assertSame($kontonummer, $konto->kontonummer);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kaputt(): iterable
    {
        yield 'empty' => ['', 'keinen MT940-Kontoauszug'];
        yield 'no MT940 at all' => ["Datum;Betrag\n01.01.2024;12,50\n", 'keinen MT940-Kontoauszug'];
        yield 'field before :20:' => [":25:12345678/1\n:20:TEST\n", 'Zeile 1: Feld :25: steht vor'];
        yield 'missing :25:' => [":20:TEST\n:60F:C240102EUR0,00\n:62F:C240102EUR0,00\n-\n", 'ab Zeile 1: Feld :25: fehlt'];
        yield 'missing closing balance' => [":20:TEST\n:25:12345678/1\n:60F:C240102EUR0,00\n-\n", 'Feld :62F:/:62M: fehlt'];
        yield 'unreadable :61:' => [":20:TEST\n:25:12345678/1\n:60F:C240102EUR0,00\n:61:2401020102X99999,99Geheim\n", 'Zeile 4: Feld :61: ist unlesbar.'];
        yield 'unreadable balance' => [":20:TEST\n:25:12345678/1\n:60F:C2401EUR1,00\n", 'Zeile 3: Feld :60F: ist unlesbar.'];
        yield 'impossible date' => [":20:TEST\n:25:12345678/1\n:60F:C241302EUR1,00\n", 'Zeile 3: Feld :60F: enthält ein ungültiges Datum.'];
    }

    #[DataProvider('kaputt')]
    public function testUnreadableFilesAreRefusedWithoutQuotingThem(string $inhalt, string $meldung): void
    {
        try {
            new Mt940Parser()->parse($inhalt);
            self::fail('Mt940Exception expected');
        } catch (Mt940Exception $e) {
            self::assertStringContainsString($meldung, $e->getMessage());
            self::assertStringNotContainsString('99999', $e->getMessage());
            self::assertStringNotContainsString('Geheim', $e->getMessage());
            self::assertStringNotContainsString('12345678', $e->getMessage());
        }
    }
}
