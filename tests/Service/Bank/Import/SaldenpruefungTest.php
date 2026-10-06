<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank\Import;

use App\Domain\BalanceCheck;
use App\Service\Bank\Import\ImportDatei;
use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\KontoauszugLeser;
use App\Service\Bank\Import\Saldenpruefpunkt;
use App\Service\Bank\Import\Saldenpruefung;
use PHPUnit\Framework\TestCase;

/**
 * The balance check of a statement import (issue #62/M9-4, docs/spec/
 * 04-bank-und-abgleich.md sections 2-4, "Pflicht-Tests": Saldenprüfung
 * ok/abweichend).
 */
final class SaldenpruefungTest extends TestCase
{
    private static function mt940(string $inhalt): ImportDatei
    {
        return new KontoauszugLeser()->lies($inhalt, ImportFormat::mt940(), []);
    }

    private static function sparkasse(): string
    {
        return ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta');
    }

    public function testMt940StatementsThatAddUpAreOk(): void
    {
        $ergebnis = Saldenpruefung::pruefe(self::mt940(self::sparkasse()), null, 'EUR');

        self::assertSame(BalanceCheck::Ok, $ergebnis->ergebnis);
        self::assertCount(3, $ergebnis->punkte, 'Two statements and the step between them.');
        self::assertSame([152340, 8410, 160750], [$ergebnis->punkte[0]->anfangCent, $ergebnis->punkte[0]->umsaetzeCent, $ergebnis->punkte[0]->schlussCent]);
        self::assertStringContainsString('Übergang', $ergebnis->punkte[1]->bezeichnung);
        self::assertNull($ergebnis->anschluss);
        self::assertSame([], $ergebnis->abweichungen());
    }

    public function testAClosingBalanceThatDoesNotAddUpIsADeviation(): void
    {
        $ergebnis = Saldenpruefung::pruefe(self::mt940(str_replace(':62F:C240312EUR1649,90', ':62F:C240312EUR1650,00', self::sparkasse())), null, 'EUR');

        self::assertSame(BalanceCheck::Abweichung, $ergebnis->ergebnis);
        self::assertCount(1, $ergebnis->abweichungen());
        self::assertSame(-10, $ergebnis->abweichungen()[0]->differenzCent());
    }

    public function testAMissingStatementBetweenTwoShowsAsAGap(): void
    {
        // The second statement starts from another balance, but adds up in itself.
        $inhalt = str_replace(
            [':60F:C240312EUR1607,50', ':62F:C240312EUR1649,90'],
            [':60F:C240312EUR1600,00', ':62F:C240312EUR1642,40'],
            self::sparkasse(),
        );
        $ergebnis = Saldenpruefung::pruefe(self::mt940($inhalt), null, 'EUR');

        self::assertSame(BalanceCheck::Abweichung, $ergebnis->ergebnis);
        self::assertCount(1, $ergebnis->abweichungen());
        self::assertStringContainsString('Übergang', $ergebnis->abweichungen()[0]->bezeichnung);
        self::assertSame(750, $ergebnis->abweichungen()[0]->differenzCent());
    }

    public function testACurrencyChangeInsideAStatementIsADeviation(): void
    {
        $ergebnis = Saldenpruefung::pruefe(self::mt940(str_replace(':62F:C240311EUR1607,50', ':62F:C240311USD1607,50', self::sparkasse())), null, 'EUR');

        self::assertSame(BalanceCheck::Abweichung, $ergebnis->ergebnis);
    }

    public function testACsvBalanceColumnIsCheckedAsAChain(): void
    {
        $datei = new KontoauszugLeser()->lies(ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'), ImportTestDaten::csvFormat(), ImportTestDaten::profile());
        $ergebnis = Saldenpruefung::pruefe($datei, null, 'EUR');

        self::assertSame(BalanceCheck::Ok, $ergebnis->ergebnis);
        self::assertCount(1, $ergebnis->punkte);
        self::assertSame([453500, -76956, 376544], [$ergebnis->punkte[0]->anfangCent, $ergebnis->punkte[0]->umsaetzeCent, $ergebnis->punkte[0]->schlussCent]);
        self::assertSame(453500, Saldenpruefung::dateiAnfangCent($datei));
    }

    public function testABrokenCsvChainNamesTheRow(): void
    {
        $inhalt = str_replace(';5.000,00;', ';5.010,00;', ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'));
        $ergebnis = Saldenpruefung::pruefe(new KontoauszugLeser()->lies($inhalt, ImportTestDaten::csvFormat(), ImportTestDaten::profile()), null, 'EUR');

        self::assertSame(BalanceCheck::Abweichung, $ergebnis->ergebnis);
        self::assertSame(['Zeile 3', 'Zeile 2'], array_map(static fn(Saldenpruefpunkt $p): string => $p->bezeichnung, $ergebnis->abweichungen()));
    }

    public function testAnAscendingCsvChainIsCheckedInItsOwnOrder(): void
    {
        $posten = [
            ImportTestDaten::posten(0, '2024-03-01', -1000, saldoNach: 9000),
            ImportTestDaten::posten(1, '2024-03-02', 2500, saldoNach: 11500),
        ];
        $datei = new ImportDatei(ImportTestDaten::csvFormat(), null, $posten, [], [], 0, true);

        self::assertSame(BalanceCheck::Ok, Saldenpruefung::pruefe($datei, 10000, 'EUR')->ergebnis);
        self::assertSame(BalanceCheck::Abweichung, Saldenpruefung::pruefe($datei, 9999, 'EUR')->ergebnis);
    }

    public function testACsvWithoutBalancesHasNothingToCheck(): void
    {
        $datei = new KontoauszugLeser()->lies(
            ImportTestDaten::datei(ImportTestDaten::CSV . 'sparkasse-csv-camt-v2.csv'),
            ImportFormat::csv(ImportTestDaten::SPARKASSE_ID),
            ImportTestDaten::profile(),
        );
        $ergebnis = Saldenpruefung::pruefe($datei, 100000, 'EUR');

        self::assertSame(BalanceCheck::NichtVerfuegbar, $ergebnis->ergebnis);
        self::assertSame([], $ergebnis->punkte);
        self::assertNull($ergebnis->anschluss, 'Without a balance in the file there is nothing to connect.');
        self::assertNull(Saldenpruefung::dateiAnfangCent($datei));
    }

    public function testTheFileIsConnectedToTheKnownBalance(): void
    {
        $datei = self::mt940(self::sparkasse());

        $passt = Saldenpruefung::pruefe($datei, 152340, 'EUR');
        self::assertSame(BalanceCheck::Ok, $passt->ergebnis);
        self::assertNotNull($passt->anschluss);
        self::assertTrue($passt->anschluss->stimmt());
        self::assertStringContainsString('11.03.2024', $passt->anschluss->bezeichnung);

        $luecke = Saldenpruefung::pruefe($datei, 150000, 'EUR');
        self::assertSame(BalanceCheck::Abweichung, $luecke->ergebnis);
        self::assertSame(-2340, $luecke->anschluss?->differenzCent());
    }

    public function testTheOpeningBalanceOfTheEarliestStatementCounts(): void
    {
        $inhalt = self::sparkasse();
        $grenze = (int) strpos($inhalt, "\r\n-\r\n") + 5;
        $umgedreht = substr($inhalt, $grenze) . substr($inhalt, 0, $grenze);

        self::assertSame(152340, Saldenpruefung::dateiAnfangCent(self::mt940($umgedreht)));
        self::assertSame(BalanceCheck::Ok, Saldenpruefung::pruefe(self::mt940($umgedreht), 152340, 'EUR')->ergebnis);
    }
}
