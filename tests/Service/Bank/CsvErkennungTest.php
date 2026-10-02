<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\Csv\CsvBuchung;
use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvException;
use App\Service\Bank\Csv\CsvFormatErkennung;
use App\Service\Bank\Csv\CsvParser;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvProfilErkennung;
use App\Service\Bank\Csv\CsvStandardprofile;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;
use PHPUnit\Framework\TestCase;

/**
 * Detection (issue #61/M9-3): the layout of an unknown export - separator,
 * character set, number and date notation, header, a suggested mapping -
 * and the profile a known export belongs to ("CSV-Profile inkl.
 * Auto-Erkennung", docs/spec/04-bank-und-abgleich.md "Pflicht-Tests").
 */
final class CsvErkennungTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/bank/';

    private static function datei(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    public function testSparkasseLayoutIsDetected(): void
    {
        $erkennung = new CsvFormatErkennung()->erkenne(self::datei('sparkasse-csv-camt-v2.csv'));

        self::assertSame(CsvZeichensatz::Windows1252, $erkennung->zeichensatz);
        self::assertSame(CsvTrennzeichen::Semikolon, $erkennung->trennzeichen);
        self::assertSame(1, $erkennung->kopfzeile);
        self::assertSame(CsvStandardprofile::SPARKASSE_KOPF, $erkennung->kopf);
        self::assertSame(CsvDatumsformat::Punkt, $erkennung->datumsformat);
        self::assertSame(CsvDezimaltrenner::Komma, $erkennung->dezimaltrenner);
        self::assertEquals([
            'buchungstag' => ['Buchungstag'],
            'valuta' => ['Valutadatum'],
            'buchungstext' => ['Buchungstext'],
            'verwendungszweck' => ['Verwendungszweck'],
            'glaeubiger_id' => ['Glaeubiger ID'],
            'mref' => ['Mandatsreferenz'],
            'eref' => ['Kundenreferenz (End-to-End)'],
            'name' => ['Beguenstigter/Zahlungspflichtiger'],
            'iban' => ['Kontonummer/IBAN'],
            'bic' => ['BIC (SWIFT-Code)'],
            'betrag' => ['Betrag'],
            'waehrung' => ['Waehrung'],
            'hinweis' => ['Info'],
        ], $erkennung->vorschlag);
    }

    public function testVrBankLayoutIsDetectedWithoutTakingTheClubsOwnAccount(): void
    {
        $erkennung = new CsvFormatErkennung()->erkenne(self::datei('vrbank.csv'));

        self::assertSame(CsvZeichensatz::Utf8, $erkennung->zeichensatz);
        self::assertSame(CsvTrennzeichen::Semikolon, $erkennung->trennzeichen);
        self::assertSame(CsvStandardprofile::VR_BANK_KOPF, $erkennung->kopf, 'without the BOM');
        self::assertSame(CsvDatumsformat::Punkt, $erkennung->datumsformat);
        self::assertSame(CsvDezimaltrenner::Komma, $erkennung->dezimaltrenner);
        self::assertSame(['IBAN Zahlungsbeteiligter'], $erkennung->vorschlag['iban'] ?? null);
        self::assertSame(['Name Zahlungsbeteiligter'], $erkennung->vorschlag['name'] ?? null);
        self::assertSame(['Saldo nach Buchung'], $erkennung->vorschlag['saldo'] ?? null);
        self::assertArrayNotHasKey('soll', $erkennung->vorschlag);
    }

    /**
     * The assistant's path end to end: detect, take the suggestion as it
     * is, read the file.
     */
    public function testAnUnknownLayoutIsDetectedAndItsSuggestionReadsTheFile(): void
    {
        $erkennung = new CsvFormatErkennung()->erkenne(self::datei('unbekannt.csv'));

        self::assertSame(CsvZeichensatz::Utf8, $erkennung->zeichensatz);
        self::assertSame(CsvTrennzeichen::Komma, $erkennung->trennzeichen);
        self::assertSame(4, $erkennung->kopfzeile, 'preamble skipped');
        self::assertSame(CsvDatumsformat::Iso, $erkennung->datumsformat);
        self::assertSame(CsvDezimaltrenner::Punkt, $erkennung->dezimaltrenner);
        self::assertEquals([
            'buchungstag' => ['Datum'],
            'valuta' => ['Wertstellung'],
            'name' => ['Empfaenger/Auftraggeber'],
            'verwendungszweck' => ['Verwendungszweck 1', 'Verwendungszweck 2'],
            'soll' => ['Soll'],
            'haben' => ['Haben'],
        ], $erkennung->vorschlag);

        $profil = CsvProfil::neu(null, 'Testbank', $erkennung->trennzeichen, $erkennung->zeichensatz,
            $erkennung->datumsformat, $erkennung->dezimaltrenner, $erkennung->vorschlag, CsvProfil::signatur($erkennung->kopf));
        $ergebnis = new CsvParser()->parse(self::datei('unbekannt.csv'), $profil);

        self::assertSame([-102000, 75050, -1240], array_map(static fn (CsvBuchung $b): int => $b->umsatz->cent, $ergebnis->buchungen));
        self::assertCount(1, $ergebnis->fehler);
    }

    public function testTabAndPipeSeparatedFiles(): void
    {
        $tab = new CsvFormatErkennung()->erkenne("Buchungstag\tBetrag\tZweck\n01.03.2024\t-1,00\tA, B; C\n02.03.2024\t2,00\tD\n");
        self::assertSame(CsvTrennzeichen::Tabulator, $tab->trennzeichen);
        self::assertSame(['Buchungstag', 'Betrag', 'Zweck'], $tab->kopf);

        $pipe = new CsvFormatErkennung()->erkenne("Datum|Umsatz\n2024-03-01|-1.50\n2024-03-02|1,000.00\n");
        self::assertSame(CsvTrennzeichen::Senkrechtstrich, $pipe->trennzeichen);
        self::assertSame(CsvDatumsformat::Iso, $pipe->datumsformat);
        self::assertSame(CsvDezimaltrenner::Punkt, $pipe->dezimaltrenner);
        self::assertEquals(['buchungstag' => ['Datum'], 'betrag' => ['Umsatz']], $pipe->vorschlag);
    }

    public function testWithoutDatesOrAmountsTheNotationStaysOpen(): void
    {
        $erkennung = new CsvFormatErkennung()->erkenne("Mannschaft;Ort\nA;B\nC;D\n");

        self::assertNull($erkennung->datumsformat);
        self::assertNull($erkennung->dezimaltrenner);
        self::assertSame([], $erkennung->vorschlag);
    }

    public function testTextWithoutColumnsIsNoCsv(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('kein Trennzeichen');

        new CsvFormatErkennung()->erkenne("Sehr geehrte Damen und Herren\nanbei der Auszug\n");
    }

    // ------------------------------------------------- profile detection

    public function testEachFixtureFindsItsShippedProfile(): void
    {
        $erkennung = new CsvProfilErkennung();
        $profile = CsvStandardprofile::alle();

        self::assertSame(CsvStandardprofile::SPARKASSE, $erkennung->finde(self::datei('sparkasse-csv-camt-v2.csv'), $profile)?->name);
        self::assertSame(CsvStandardprofile::SPARKASSE, $erkennung->finde(self::datei('sparkasse-csv-camt-v8.csv'), $profile)?->name, 'V8: no exact signature, still Sparkasse');
        self::assertSame(CsvStandardprofile::VR_BANK, $erkennung->finde(self::datei('vrbank.csv'), $profile)?->name);
        self::assertNull($erkennung->finde(self::datei('unbekannt.csv'), $profile));
        self::assertNull($erkennung->finde("\0binary", $profile));
    }

    public function testTheClubsOwnProfileIsFoundForItsLayout(): void
    {
        $eigenes = CsvProfil::neu(7, 'Testbank', CsvTrennzeichen::Komma, CsvZeichensatz::Automatisch, CsvDatumsformat::Iso, CsvDezimaltrenner::Punkt,
            ['buchungstag' => ['Datum'], 'soll' => ['Soll'], 'haben' => ['Haben']], null);

        $gefunden = new CsvProfilErkennung()->finde(self::datei('unbekannt.csv'), [...CsvStandardprofile::alle(), $eigenes]);

        self::assertSame(7, $gefunden?->id);
    }

    public function testTheExactSignatureWinsOverMoreMatchingFields(): void
    {
        $kopf = ['Buchungstag', 'Betrag', 'Verwendungszweck'];
        $datei = implode(';', $kopf) . "\n01.03.24;1,00;Test\n";
        $neu = static fn (int $id, array $zuordnung, ?string $signatur): CsvProfil => CsvProfil::neu(
            $id, 'P' . $id, CsvTrennzeichen::Semikolon, CsvZeichensatz::Automatisch, CsvDatumsformat::Punkt, CsvDezimaltrenner::Komma, $zuordnung, $signatur,
        );
        $mehr = $neu(1, ['buchungstag' => ['Buchungstag'], 'betrag' => ['Betrag'], 'verwendungszweck' => ['Verwendungszweck']], null);
        $genau = $neu(2, ['buchungstag' => ['Buchungstag'], 'betrag' => ['Betrag']], CsvProfil::signatur($kopf));

        self::assertSame(1, new CsvProfilErkennung()->finde($datei, [$mehr, $neu(3, ['buchungstag' => ['Buchungstag'], 'betrag' => ['Betrag']], null)])?->id);
        self::assertSame(2, new CsvProfilErkennung()->finde($datei, [$mehr, $genau])?->id);
    }

    public function testOnATieTheShippedProfileWins(): void
    {
        $kopie = CsvStandardprofile::sparkasse();
        $eigenes = new CsvProfil(9, 'Kopie', false, $kopie->trennzeichen, $kopie->zeichensatz, $kopie->datumsformat, $kopie->dezimaltrenner, $kopie->zuordnung, null);

        $gefunden = new CsvProfilErkennung()->finde(self::datei('sparkasse-csv-camt-v8.csv'), [$eigenes, CsvStandardprofile::sparkasse()]);

        self::assertSame(CsvStandardprofile::SPARKASSE, $gefunden?->name);
    }
}
