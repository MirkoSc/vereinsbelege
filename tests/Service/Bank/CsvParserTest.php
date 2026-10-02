<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\Csv\CsvBuchung;
use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvErgebnis;
use App\Service\Bank\Csv\CsvException;
use App\Service\Bank\Csv\CsvParser;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvProfilUngueltig;
use App\Service\Bank\Csv\CsvStandardprofile;
use App\Service\Bank\Csv\CsvTabelle;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;
use App\Service\Bank\Csv\CsvZeilenfehler;
use App\Service\Bank\Umsatzdetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV import with profiles (issue #61/M9-3, docs/spec/04-bank-und-abgleich.md
 * "Pflicht-Tests": CSV-Profile, Soll/Haben-Spalten, Dezimalkomma,
 * Tausenderpunkt, Sparkasse- und VR-Bank-Fixtures für CSV-CAMT).
 *
 * The fixtures are synthetic files in the layout of the banks, see
 * tests/fixtures/bank/README.md. Expected values are typed by hand.
 */
final class CsvParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/bank/';

    private static function lies(string $datei, CsvProfil $profil): CsvErgebnis
    {
        return new CsvParser()->parse((string) file_get_contents(self::FIXTURES . $datei), $profil);
    }

    private static function details(CsvBuchung $buchung): Umsatzdetails
    {
        self::assertNotNull($buchung->umsatz->details);

        return $buchung->umsatz->details;
    }

    /**
     * @return list<int>
     */
    private static function cents(CsvErgebnis $ergebnis): array
    {
        return array_map(static fn (CsvBuchung $b): int => $b->umsatz->cent, $ergebnis->buchungen);
    }

    /**
     * @return list<string>
     */
    private static function buchungstage(CsvErgebnis $ergebnis): array
    {
        return array_map(static fn (CsvBuchung $b): string => $b->umsatz->buchungsdatum->format('Y-m-d'), $ergebnis->buchungen);
    }

    /** The assistant's profile for tests/fixtures/bank/unbekannt.csv. */
    private static function unbekanntProfil(): CsvProfil
    {
        return CsvProfil::neu(null, 'Testbank', CsvTrennzeichen::Komma, CsvZeichensatz::Automatisch, CsvDatumsformat::Iso, CsvDezimaltrenner::Punkt, [
            'buchungstag' => ['Datum'],
            'valuta' => ['Wertstellung'],
            'name' => ['Empfaenger/Auftraggeber'],
            'verwendungszweck' => ['Verwendungszweck 1', 'Verwendungszweck 2'],
            'soll' => ['Soll'],
            'haben' => ['Haben'],
        ], null);
    }

    public function testFixturesKeepTheBanksEncodingAndLineEndings(): void
    {
        foreach (['sparkasse-csv-camt-v2.csv', 'sparkasse-csv-camt-v8.csv'] as $datei) {
            $inhalt = (string) file_get_contents(self::FIXTURES . $datei);
            self::assertFalse(mb_check_encoding($inhalt, 'UTF-8'), $datei . ' must stay Windows-1252');
            self::assertStringContainsString("\r\n", $inhalt, $datei . ' must keep CRLF');
        }
        self::assertStringStartsWith("\xEF\xBB\xBF", (string) file_get_contents(self::FIXTURES . 'vrbank.csv'), 'VR Bank fixture keeps its BOM');
    }

    public function testSparkasseCsvCamtV2(): void
    {
        $ergebnis = self::lies('sparkasse-csv-camt-v2.csv', CsvStandardprofile::sparkasse());

        self::assertSame(1, $ergebnis->kopfzeile);
        self::assertSame([], $ergebnis->fehler);
        self::assertSame(1, $ergebnis->vorgemerkt, 'the pending row is left out');
        self::assertSame([-8900, 125000, -4590, -750], self::cents($ergebnis), 'Dezimalkomma, Tausenderpunkt');
        self::assertSame(['2024-03-28', '2024-03-27', '2024-03-26', '2024-01-02'], self::buchungstage($ergebnis));
        self::assertSame([3, 4, 5, 7], array_map(static fn (CsvBuchung $b): int => $b->zeile, $ergebnis->buchungen), 'line numbers count the line break inside a quoted cell');

        [$strom, $beitrag, $karte, $abschluss] = $ergebnis->buchungen;

        self::assertSame('EUR', $strom->waehrung);
        self::assertNull($strom->saldoCent);
        self::assertSame('2024-03-28', $strom->umsatz->valuta->format('Y-m-d'));
        self::assertFalse($strom->umsatz->storno);
        self::assertSame('E2E-STROM-2403', $strom->umsatz->kundenreferenz);
        $details = self::details($strom);
        self::assertFalse($details->strukturiert);
        self::assertSame('FOLGELASTSCHRIFT', $details->buchungstext);
        self::assertSame('Strom Abschlag Maerz Vertragskonto 4711', $details->verwendungszweck);
        self::assertSame('Stadtwerke Musterstadt GmbH', $details->name);
        self::assertSame('DE02120300000000202051', $details->iban);
        self::assertSame('BYLADEM1001', $details->bic);
        self::assertSame(['EREF' => 'E2E-STROM-2403', 'MREF' => 'MANDAT-0815', 'CRED' => 'DE98ZZZ09999999999'], $details->sepa);

        self::assertSame('Jörg Müßig', self::details($beitrag)->name, 'Windows-1252 read as such');
        self::assertSame('Mitgliedsbeitrag 2024 Jugend; Familie Müßig', self::details($beitrag)->verwendungszweck, 'separator inside quotes');

        self::assertSame('Getraenkemarkt Beispiel Kasse 3', self::details($karte)->verwendungszweck, 'line break inside a cell becomes a space');
        self::assertNull(self::details($karte)->iban);
        self::assertNull(self::details($karte)->bic);
        self::assertSame([], self::details($karte)->sepa);
        self::assertSame('NONREF', $karte->umsatz->kundenreferenz);

        self::assertSame('2024-01-02', $abschluss->umsatz->buchungsdatum->format('Y-m-d'));
        self::assertSame('2023-12-29', $abschluss->umsatz->valuta->format('Y-m-d'), 'value date in the old year');
        self::assertNull(self::details($abschluss)->name);

        $zeitraum = $ergebnis->zeitraum() ?? self::fail('no period');
        self::assertSame(['2024-01-02', '2024-03-28'], [$zeitraum[0]->format('Y-m-d'), $zeitraum[1]->format('Y-m-d')]);
    }

    /**
     * The V8 variant (an extra column, four-digit years) reads to the very
     * same bookings with the same profile.
     */
    public function testSparkasseCsvCamtV8ReadsLikeV2(): void
    {
        $v2 = self::lies('sparkasse-csv-camt-v2.csv', CsvStandardprofile::sparkasse());
        $v8 = self::lies('sparkasse-csv-camt-v8.csv', CsvStandardprofile::sparkasse());

        self::assertSame([], $v8->fehler);
        self::assertSame(1, $v8->vorgemerkt);
        self::assertSame(self::cents($v2), self::cents($v8));
        self::assertSame(self::buchungstage($v2), self::buchungstage($v8));
        self::assertEquals(
            array_map(self::details(...), $v2->buchungen),
            array_map(self::details(...), $v8->buchungen),
        );
    }

    public function testVrBankWithBalanceAfterEachBooking(): void
    {
        $ergebnis = self::lies('vrbank.csv', CsvStandardprofile::vrBank());

        self::assertSame([], $ergebnis->fehler);
        self::assertSame(0, $ergebnis->vorgemerkt);
        self::assertSame([-123456, 50000, -3500], self::cents($ergebnis));
        self::assertSame([376544, 500000, 450000], array_map(static fn (CsvBuchung $b): ?int => $b->saldoCent, $ergebnis->buchungen));

        [$trikots, $spende, $versicherung] = $ergebnis->buchungen;
        self::assertSame('Sportartikel Beispiel GmbH', self::details($trikots)->name);
        self::assertSame('RE 2024-117 Trikots E-Jugend', self::details($trikots)->verwendungszweck);
        self::assertSame('Überweisung', self::details($trikots)->buchungstext, 'UTF-8 with BOM');
        self::assertSame('COBADEFFXXX', self::details($trikots)->bic);
        self::assertSame('Förderverein Beispiel e.V.', self::details($spende)->name);
        self::assertSame('2024-03-26', $versicherung->umsatz->buchungsdatum->format('Y-m-d'));
        self::assertSame('2024-03-25', $versicherung->umsatz->valuta->format('Y-m-d'));
        self::assertSame(['MREF' => 'VERS-4711', 'CRED' => 'DE22ZZZ00000012345'], self::details($versicherung)->sepa);
        self::assertSame('DE68210501700012345678', self::details($versicherung)->iban, 'the counterparty, not the club account');
    }

    /**
     * Soll/Haben columns, decimal point with thousands comma, a preamble,
     * two purpose columns and a row without an amount.
     */
    public function testAProfileFromTheAssistantWithDebitAndCreditColumns(): void
    {
        $ergebnis = self::lies('unbekannt.csv', self::unbekanntProfil());

        self::assertSame(4, $ergebnis->kopfzeile, 'the preamble is skipped');
        self::assertSame([-102000, 75050, -1240], self::cents($ergebnis));
        self::assertSame(['Bahnmiete Training Maerz', 'Zuschuss Uebungsleiter', 'Brötchen Turnier'],
            array_map(static fn (CsvBuchung $b): string => self::details($b)->verwendungszweck, $ergebnis->buchungen));
        self::assertSame('Bäckerei Krümel, Inh. Test', self::details($ergebnis->buchungen[2])->name);
        self::assertSame('2024-03-08', $ergebnis->buchungen[2]->umsatz->valuta->format('Y-m-d'));

        self::assertEquals([new CsvZeilenfehler(8, 'Zeile 8, Spalte „Soll“: weder Soll noch Haben gefüllt.')], $ergebnis->fehler);
    }

    public function testDebitCountsNegativeWhateverSignTheBankWrites(): void
    {
        $profil = self::unbekanntProfil();
        $kopf = "Datum,Soll,Haben\r\n";

        $ergebnis = new CsvParser()->parse($kopf . "2024-03-01,-12.40,\r\n2024-03-02,,+3.00\r\n2024-03-03,1.00,2.00\r\n", $profil);

        self::assertSame([-1240, 300], self::cents($ergebnis));
        self::assertEquals([new CsvZeilenfehler(4, 'Zeile 4, Spalte „Soll“: Soll und Haben zugleich gefüllt.')], $ergebnis->fehler);
    }

    public function testRowErrorsNameLineAndColumnButNeverTheContent(): void
    {
        $profil = CsvStandardprofile::vrBank();
        $kopf = implode(';', CsvStandardprofile::VR_BANK_KOPF) . "\n";
        $zeile = static fn (string $tag, string $betrag, string $waehrung = 'EUR', string $saldo = ''): string => implode(';', [
            'Konto', 'DE89370400440532013000', '', '', $tag, '', 'Geheim Name', '', '', '', 'Geheimer Zweck', $betrag, $waehrung, $saldo, '', '', '', '', '',
        ]) . "\n";

        $ergebnis = new CsvParser()->parse(
            $kopf
            . $zeile('31.02.2024', '1,00')
            . $zeile('01.03.2024', '12,345')
            . $zeile('01.03.2024', '')
            . $zeile('01.03.2024', '1,00', 'Euro')
            . $zeile('01.03.2024', '1,00', 'EUR', 'viel')
            . "Konto;zu;wenig\n"
            . rtrim($zeile('01.03.2024', '1,00')) . ";zuviel\n",
            $profil,
        );

        self::assertSame([], $ergebnis->buchungen);
        self::assertSame([
            'Zeile 2, Spalte „Buchungstag“: kein Datum im Format TT.MM.JJJJ (auch TT.MM.JJ).',
            'Zeile 3, Spalte „Betrag“: keine Zahl im Format Komma (1.234,56).',
            'Zeile 4, Spalte „Betrag“: kein Betrag.',
            'Zeile 5, Spalte „Waehrung“: keine Währungsangabe wie „EUR“.',
            'Zeile 6, Spalte „Saldo nach Buchung“: keine Zahl im Format Komma (1.234,56).',
            'Zeile 7: 3 statt 19 Spalten.',
            'Zeile 8: 20 statt 19 Spalten.',
        ], array_map(static fn (CsvZeilenfehler $f): string => $f->meldung, $ergebnis->fehler));
        foreach ($ergebnis->fehler as $fehler) {
            foreach (['Geheim', '31.02', '12,345', 'Euro', 'viel', 'DE89'] as $inhalt) {
                self::assertStringNotContainsString($inhalt, $fehler->meldung);
            }
        }
    }

    public function testTrailingEmptyCellsAreTolerated(): void
    {
        $ergebnis = new CsvParser()->parse("Datum,Soll,Haben,\r\n2024-03-01,1.00,,\r\n2024-03-02,,2.00,,\r\n", self::unbekanntProfil());

        self::assertSame([-100, 200], self::cents($ergebnis));
        self::assertSame([], $ergebnis->fehler);
    }

    public function testAFileWithoutTheProfilesHeaderIsRefused(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('Die Datei passt nicht zum Format „Sparkasse CSV-CAMT“: Keine Kopfzeile mit einer Spalte für „Betrag (mit Vorzeichen)“ gefunden.');

        new CsvParser()->parse("Buchungstag;Umsatz\n01.03.24;1,00\n", CsvStandardprofile::sparkasse());
    }

    public function testABinaryFileIsRefused(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('keine Textdatei');

        new CsvParser()->parse("%PDF-1.7\n\0\0\x01binary", CsvStandardprofile::sparkasse());
    }

    public function testAWindows1252FileIsRefusedWhenTheProfileSaysUtf8(): void
    {
        $profil = CsvProfil::neu(null, 'UTF-8', CsvTrennzeichen::Semikolon, CsvZeichensatz::Utf8, CsvDatumsformat::Punkt, CsvDezimaltrenner::Komma,
            ['buchungstag' => ['Buchungstag'], 'betrag' => ['Betrag']], null);

        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('nicht in UTF-8');

        new CsvParser()->parse((string) file_get_contents(self::FIXTURES . 'sparkasse-csv-camt-v2.csv'), $profil);
    }

    // ------------------------------------------------------------ formats

    /**
     * @return iterable<string, array{CsvDezimaltrenner, string, ?int}>
     */
    public static function betraege(): iterable
    {
        yield 'Komma' => [CsvDezimaltrenner::Komma, '12,50', 1250];
        yield 'Komma, eine Stelle' => [CsvDezimaltrenner::Komma, '12,5', 1250];
        yield 'Tausenderpunkt' => [CsvDezimaltrenner::Komma, '1.234.567,89', 123456789];
        yield 'Tausenderpunkt ohne Nachkomma' => [CsvDezimaltrenner::Komma, '1.234', 123400];
        yield 'Minus vorn' => [CsvDezimaltrenner::Komma, '-0,05', -5];
        yield 'Minus hinten' => [CsvDezimaltrenner::Komma, '50,00-', -5000];
        yield 'Plus' => [CsvDezimaltrenner::Komma, '+3,00', 300];
        yield 'Leerzeichen und Euro' => [CsvDezimaltrenner::Komma, '1 234,56 €', 123456];
        yield 'drei Nachkommastellen' => [CsvDezimaltrenner::Komma, '12,345', null];
        yield 'falscher Trenner' => [CsvDezimaltrenner::Komma, '12.50', null];
        yield 'Text' => [CsvDezimaltrenner::Komma, 'zwölf', null];
        yield 'leer' => [CsvDezimaltrenner::Komma, '', null];
        yield 'Punkt' => [CsvDezimaltrenner::Punkt, '12.50', 1250];
        yield 'Tausenderkomma' => [CsvDezimaltrenner::Punkt, '1,234.56', 123456];
        yield 'Punkt, falscher Trenner' => [CsvDezimaltrenner::Punkt, '12,50', null];
        yield 'zu groß' => [CsvDezimaltrenner::Punkt, '10000000000.00', null];
    }

    #[DataProvider('betraege')]
    public function testAmountsBecomeIntegerCents(CsvDezimaltrenner $trenner, string $text, ?int $cent): void
    {
        self::assertSame($cent, $trenner->cent($text));
    }

    /**
     * @return iterable<string, array{CsvDatumsformat, string, ?string}>
     */
    public static function daten(): iterable
    {
        yield 'Punkt vierstellig' => [CsvDatumsformat::Punkt, '31.12.2024', '2024-12-31'];
        yield 'Punkt zweistellig' => [CsvDatumsformat::Punkt, '31.12.24', '2024-12-31'];
        yield 'Punkt einstellig' => [CsvDatumsformat::Punkt, '1.3.2024', '2024-03-01'];
        yield 'Punkt 30. Februar' => [CsvDatumsformat::Punkt, '30.02.2024', null];
        yield 'Punkt, aber ISO' => [CsvDatumsformat::Punkt, '2024-12-31', null];
        yield 'ISO' => [CsvDatumsformat::Iso, '2024-02-29', '2024-02-29'];
        yield 'ISO ungültig' => [CsvDatumsformat::Iso, '2023-02-29', null];
        yield 'Schrägstrich' => [CsvDatumsformat::Schraegstrich, '05/03/2024', '2024-03-05'];
        yield 'Schrägstrich zweistellig' => [CsvDatumsformat::Schraegstrich, '05/03/99', '1999-03-05'];
    }

    #[DataProvider('daten')]
    public function testDates(CsvDatumsformat $format, string $text, ?string $erwartet): void
    {
        self::assertSame($erwartet, $format->parse($text)?->format('Y-m-d'));
    }

    public function testTheSplitterFollowsRfc4180(): void
    {
        $zeilen = CsvTabelle::zerlege("a;\"b;c\";\"d \"\"e\"\"\"\r\n\"f\ng\";h\rlast;x", CsvTrennzeichen::Semikolon);

        self::assertSame([1, 2, 4], array_map(static fn ($z): int => $z->zeile, $zeilen));
        self::assertSame(['a', 'b;c', 'd "e"'], $zeilen[0]->zellen);
        self::assertSame(["f\ng", 'h'], $zeilen[1]->zellen);
        self::assertSame(['last', 'x'], $zeilen[2]->zellen, 'last line without line break');
    }

    // ------------------------------------------------------------ profile

    public function testAProfileNeedsBookingDateAndAmount(): void
    {
        $neu = static fn (array $zuordnung, string $name = 'Bank'): CsvProfil => CsvProfil::neu(
            null, $name, CsvTrennzeichen::Semikolon, CsvZeichensatz::Automatisch, CsvDatumsformat::Punkt, CsvDezimaltrenner::Komma, $zuordnung, null,
        );
        $faelle = [
            [[], 'buchungstag', 'Buchungstag'],
            [['buchungstag' => ['Datum']], 'betrag', 'Betrag'],
            [['buchungstag' => ['Datum'], 'betrag' => ['Betrag'], 'soll' => ['Soll']], 'betrag', 'nicht beides'],
            [['buchungstag' => ['Datum'], 'haben' => ['Haben']], 'soll', 'Soll'],
            [['buchungstag' => ['Datum'], 'soll' => ['Soll']], 'haben', 'Haben'],
            [['buchungstag' => ['  ']], 'buchungstag', 'Buchungstag'],
        ];
        foreach ($faelle as [$zuordnung, $feld, $meldung]) {
            try {
                $neu($zuordnung);
                self::fail('accepted: ' . json_encode($zuordnung));
            } catch (CsvProfilUngueltig $e) {
                self::assertSame($feld, $e->feld);
                self::assertStringContainsString($meldung, $e->getMessage());
            }
        }

        try {
            $neu(['buchungstag' => ['Datum'], 'betrag' => ['Betrag']], '   ');
            self::fail('accepted an empty name');
        } catch (CsvProfilUngueltig $e) {
            self::assertSame('name', $e->feld);
        }

        $profil = $neu(['buchungstag' => [' Datum ', 'Datum', ''], 'betrag' => ['Betrag']], '  Meine   Bank ');
        self::assertSame('Meine Bank', $profil->name);
        self::assertSame(['buchungstag' => ['Datum'], 'betrag' => ['Betrag']], $profil->zuordnung);
        self::assertFalse($profil->mitgeliefert);
    }

    public function testColumnNamesMatchIgnoringCaseUmlautsAndSpaces(): void
    {
        $kopf = ['  BUCHUNGSTAG ', 'Gläubiger-ID', 'Betrag'];
        $profil = CsvStandardprofile::sparkasse();

        self::assertTrue($profil->passtZu($kopf));
        self::assertSame(['buchungstag' => [0], 'betrag' => [2], 'glaeubiger_id' => [1]], $profil->aufloesen($kopf));
        self::assertSame(CsvProfil::signatur(['Buchungstag', 'Betrag']), CsvProfil::signatur([' buchungstag', 'BETRAG ']));
        self::assertNotSame(CsvProfil::signatur(['Buchungstag', 'Betrag']), CsvProfil::signatur(['Betrag', 'Buchungstag']));
    }
}
