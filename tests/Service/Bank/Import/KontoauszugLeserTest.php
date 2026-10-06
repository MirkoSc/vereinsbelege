<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank\Import;

use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\ImportPosten;
use App\Service\Bank\Import\KontoauszugLeser;
use App\Service\Bank\Import\KontoauszugUnlesbar;
use PHPUnit\Framework\TestCase;

/**
 * Reading a statement file for the import (issue #62/M9-4, docs/spec/
 * 04-bank-und-abgleich.md section 4): format detection, one shape for
 * MT940 and CSV, chronological order, refusals that never quote the file.
 */
final class KontoauszugLeserTest extends TestCase
{
    public function testMt940IsRecognisedByItsFirstFields(): void
    {
        $leser = new KontoauszugLeser();

        self::assertTrue($leser->istMt940(ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta')));
        self::assertTrue($leser->istMt940(ImportTestDaten::datei(ImportTestDaten::MT940 . 'vrbank.sta')));
        self::assertTrue($leser->istMt940("{1:F01TESTDEFFAXXX0000000000}{4:\r\n:20:X\r\n"));
        self::assertTrue($leser->istMt940("\r\n\r\n:20:STARTUMSE\r\n"));
        self::assertFalse($leser->istMt940(ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv')));
        self::assertFalse($leser->istMt940(ImportTestDaten::datei(ImportTestDaten::CSV . 'sparkasse-csv-camt-v2.csv')));
        self::assertFalse($leser->istMt940(''));
    }

    public function testTheFormatOfEveryFixtureIsDetected(): void
    {
        $leser = new KontoauszugLeser();
        $profile = ImportTestDaten::profile();

        self::assertSame('mt940', $leser->erkenne(ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta'), $profile)->toString());
        self::assertSame('mt940', $leser->erkenne(ImportTestDaten::datei(ImportTestDaten::MT940 . 'vrbank.sta'), $profile)->toString());
        foreach (['sparkasse-csv-camt-v2.csv', 'sparkasse-csv-camt-v8.csv'] as $datei) {
            self::assertSame('csv:' . ImportTestDaten::SPARKASSE_ID, $leser->erkenne(ImportTestDaten::datei(ImportTestDaten::CSV . $datei), $profile)->toString(), $datei);
        }
        self::assertSame('csv:' . ImportTestDaten::VR_BANK_ID, $leser->erkenne(ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'), $profile)->toString());
    }

    public function testAnUnknownCsvLayoutPointsToTheCsvFormats(): void
    {
        try {
            new KontoauszugLeser()->erkenne(ImportTestDaten::datei(ImportTestDaten::CSV . 'unbekannt.csv'), ImportTestDaten::profile());
            self::fail('An unknown layout must be refused.');
        } catch (KontoauszugUnlesbar $e) {
            self::assertTrue($e->csvFormatFehlt);
            self::assertStringContainsString('CSV-Formate', $e->getMessage());
        }
    }

    public function testAnMt940FileBecomesPostingsWithItsAccountLine(): void
    {
        $datei = new KontoauszugLeser()->lies(ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta'), ImportFormat::mt940(), []);

        self::assertNotNull($datei->konto);
        self::assertSame('12345678', $datei->konto->blz);
        self::assertSame('0001234567', $datei->konto->kontonummer);
        self::assertCount(2, $datei->auszuege);
        self::assertSame([-4590, 25000, -12000, 4590, -350], array_map(static fn(ImportPosten $p): int => $p->umsatz->cent, $datei->posten));
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(ImportPosten $p): int => $p->nr, $datei->posten));
        self::assertTrue($datei->hatSalden());
        self::assertSame(['2024-03-11', '2024-03-12'], array_map(static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'), $datei->zeitraum() ?? []));
        self::assertSame('EUR', $datei->posten[0]->waehrung);
    }

    public function testAFileWithStatementsOfSeveralAccountsIsRefusedWithoutQuotingThem(): void
    {
        $inhalt = ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta') . ImportTestDaten::datei(ImportTestDaten::MT940 . 'vrbank.sta');

        try {
            new KontoauszugLeser()->lies($inhalt, ImportFormat::mt940(), []);
            self::fail('Statements of two accounts must be refused.');
        } catch (KontoauszugUnlesbar $e) {
            self::assertStringContainsString('mehrerer Konten', $e->getMessage());
            self::assertStringNotContainsString('12345678', $e->getMessage());
            self::assertStringNotContainsString('DE93', $e->getMessage());
        }
    }

    public function testStatementsOfOneAccountInDifferentNotationsAreOneAccount(): void
    {
        $inhalt = ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta');
        $inhalt = preg_replace('/:25:12345678\/0001234567(\r\n:28C:00013)/', ':25:12345678/1234567$1', $inhalt) ?? '';

        self::assertCount(2, new KontoauszugLeser()->lies($inhalt, ImportFormat::mt940(), [])->auszuege);
    }

    public function testABrokenMt940FileNamesTheLineButNotTheContent(): void
    {
        $inhalt = str_replace(':61:2403110311CR250,00', ':61:2403110311CR2x50,00', ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta'));

        try {
            new KontoauszugLeser()->lies($inhalt, ImportFormat::mt940(), []);
            self::fail('A broken file must be refused.');
        } catch (KontoauszugUnlesbar $e) {
            self::assertStringContainsString('Zeile', $e->getMessage());
            self::assertStringNotContainsString('2x50', $e->getMessage());
        }
    }

    public function testACsvExportNewestFirstIsTurnedRound(): void
    {
        $datei = new KontoauszugLeser()->lies(ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'), ImportTestDaten::csvFormat(), ImportTestDaten::profile());

        self::assertNull($datei->konto);
        self::assertSame(['2024-03-26', '2024-03-27', '2024-03-28'], array_map(static fn(ImportPosten $p): string => $p->umsatz->buchungsdatum->format('Y-m-d'), $datei->posten));
        self::assertSame([450000, 500000, 376544], array_map(static fn(ImportPosten $p): ?int => $p->saldoNachCent, $datei->posten));
        self::assertSame([0, 1, 2], array_map(static fn(ImportPosten $p): int => $p->nr, $datei->posten));
        self::assertTrue($datei->saldoNachBuchung);
        self::assertTrue($datei->hatSalden());
    }

    public function testASingleDayCsvIsOrderedByItsBalanceColumn(): void
    {
        $inhalt = str_replace(['28.03.2024', '27.03.2024', '26.03.2024'], '28.03.2024', ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'));
        $datei = new KontoauszugLeser()->lies($inhalt, ImportTestDaten::csvFormat(), ImportTestDaten::profile());

        self::assertSame([450000, 500000, 376544], array_map(static fn(ImportPosten $p): ?int => $p->saldoNachCent, $datei->posten));
    }

    public function testCsvRowErrorsAndPendingRowsAreReportedNotImported(): void
    {
        $datei = new KontoauszugLeser()->lies(
            ImportTestDaten::datei(ImportTestDaten::CSV . 'sparkasse-csv-camt-v2.csv'),
            ImportFormat::csv(ImportTestDaten::SPARKASSE_ID),
            ImportTestDaten::profile(),
        );

        self::assertCount(4, $datei->posten);
        self::assertSame(1, $datei->vorgemerkt);
        self::assertSame([], $datei->fehler);
        self::assertFalse($datei->hatSalden(), 'The Sparkasse export has no balance column.');
        self::assertSame('2024-01-02', $datei->posten[0]->umsatz->buchungsdatum->format('Y-m-d'));

        $kaputt = str_replace('"-89,00"', '"-89,0x"', ImportTestDaten::datei(ImportTestDaten::CSV . 'sparkasse-csv-camt-v2.csv'));
        $datei = new KontoauszugLeser()->lies($kaputt, ImportFormat::csv(ImportTestDaten::SPARKASSE_ID), ImportTestDaten::profile());
        self::assertCount(3, $datei->posten);
        self::assertCount(1, $datei->fehler);
        self::assertStringNotContainsString('89,0x', $datei->fehler[0]->meldung);
    }

    public function testACsvFormatThatNoLongerExistsIsRefused(): void
    {
        $this->expectException(KontoauszugUnlesbar::class);
        $this->expectExceptionMessage('gibt es nicht mehr');

        new KontoauszugLeser()->lies(ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv'), ImportFormat::csv(99), ImportTestDaten::profile());
    }

    public function testTheFormatRoundTripsThroughItsStoredForm(): void
    {
        self::assertTrue(ImportFormat::fromString('mt940')->istMt940());
        self::assertSame(7, ImportFormat::fromString('csv:7')->csvProfilId);
        self::assertSame('csv:7', ImportFormat::csv(7)->toString());

        $this->expectException(\InvalidArgumentException::class);
        ImportFormat::fromString('csv:0');
    }
}
