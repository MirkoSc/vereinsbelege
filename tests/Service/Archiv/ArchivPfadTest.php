<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiv;

use App\Domain\InvoiceDirection;
use App\Service\Archiv\ArchivPfad;
use App\Service\Archiv\ArchivVorbelegung;
use App\Service\Archiv\DateinamenMuster;
use App\Service\Export\PfadMuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The folder semantics of the archive import (issue #77/M12-3,
 * docs/spec/05-auswertung-und-export.md section 3 and "Pflicht-Tests":
 * Ordner-Semantik Kasse/Wiedervorlage). All names are made up.
 */
final class ArchivPfadTest extends TestCase
{
    private const array AUSGABE = [3 => 'Sportgeräte', 4 => 'Getränke'];
    private const array EINNAHME = [7 => 'Mitgliedsbeiträge', 4 => 'Getränke'];

    public function testAFlatFileGivesSupplierAndDate(): void
    {
        $v = $this->analysiere('Bauhaus 03.02.2026.pdf');

        self::assertSame('Bauhaus', $v->lieferant);
        self::assertSame('2026-02-03', $v->datum?->format('Y-m-d'));
        self::assertFalse($v->kasse);
        self::assertFalse($v->wiedervorlage);
        self::assertSame(InvoiceDirection::Ausgabe, $v->richtung);
        self::assertTrue($v->musterPasst);
        self::assertSame([], $v->hinweise);
        self::assertNull($v->ueberspringen);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function kasse(): iterable
    {
        yield 'under the month' => ['2026/02. Februar/Kasse/Kiosk 03.02.2026.pdf'];
        yield 'at the top' => ['Kasse/Kiosk 03.02.2026.pdf'];
        yield 'lower case' => ['2026/kasse/Kiosk 03.02.2026.pdf'];
    }

    #[DataProvider('kasse')]
    public function testACashFolderMarksTheReceiptAsPaidInCash(string $pfad): void
    {
        $v = $this->analysiere($pfad);

        self::assertTrue($v->kasse);
        self::assertSame('Kiosk', $v->lieferant);
        self::assertSame([], $v->hinweise);
    }

    public function testAFolderThatOnlyContainsKasseIsNoCashFolder(): void
    {
        self::assertFalse($this->analysiere('Kassenbuch/Kiosk 03.02.2026.pdf')->kasse);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wiedervorlage(): iterable
    {
        yield 'old archive' => ['2026/Wiedervorlage/Bauhaus 03.02.2026.pdf'];
        yield 'own export' => ['Belege_2026/_Wiedervorlage/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf'];
    }

    #[DataProvider('wiedervorlage')]
    public function testAWiedervorlageFolderStartsTheReceiptInWiedervorlage(string $pfad): void
    {
        $v = $this->analysiere($pfad);

        self::assertTrue($v->wiedervorlage);
        self::assertSame('Bauhaus', $v->lieferant);
        self::assertSame([], $v->hinweise);
    }

    public function testTheDefaultExportLayoutReadsBack(): void
    {
        $pfad = implode('/', ['Belege_2026', ...PfadMuster::standard()->aufloesen(new \App\Service\Export\BelegPfadDaten(
            lieferant: 'Stadtwerke Musterstadt',
            datum: new \DateTimeImmutable('2026-01-15'),
            richtung: InvoiceDirection::Ausgabe,
            kategorie: null,
            nr: '',
            betragCent: 0,
            waehrung: 'EUR',
            kasse: false,
        ))]) . '.pdf';
        $v = $this->analysiere($pfad);

        self::assertSame('Belege_2026/Stadtwerke Musterstadt/2026/01. Januar/Stadtwerke Musterstadt 15.01.2026.pdf', $pfad);
        self::assertSame('Stadtwerke Musterstadt', $v->lieferant);
        self::assertSame('2026-01-15', $v->datum?->format('Y-m-d'));
        self::assertSame([], $v->hinweise);
    }

    public function testTheArchiveLayoutWithCashFolderReadsBack(): void
    {
        $v = $this->analysiere('2026/05. Mai/Kasse/Kiosk am Platz 17.05.2026 (2).pdf');

        self::assertTrue($v->kasse);
        self::assertSame('Kiosk am Platz', $v->lieferant);
        self::assertSame('2026-05-17', $v->datum?->format('Y-m-d'));
        self::assertSame([], $v->hinweise);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function abweichendeOrdner(): iterable
    {
        yield 'year' => ['2025/Bauhaus 03.02.2026.pdf', '„2025“'];
        yield 'month number' => ['2026/03/Bauhaus 03.02.2026.pdf', '„03“'];
        yield 'month name' => ['2026/März/Bauhaus 03.02.2026.pdf', '„märz“'];
        yield 'month of the export' => ['2026/04. April/Bauhaus 03.02.2026.pdf', '„04. april“'];
        yield 'export root' => ['Belege_2025/Bauhaus 03.02.2026.pdf', '„belege_2025“'];
    }

    #[DataProvider('abweichendeOrdner')]
    public function testYearAndMonthFoldersOnlyCheckTheDate(string $pfad, string $ordner): void
    {
        $v = $this->analysiere($pfad);

        // The file name wins; the folder is a hint, never the date.
        self::assertSame('2026-02-03', $v->datum?->format('Y-m-d'));
        self::assertCount(1, $v->hinweise);
        self::assertStringContainsString($ordner, $v->hinweise[0]);
    }

    public function testFittingYearAndMonthFoldersGiveNoHint(): void
    {
        foreach (['2026/02/x', '2026/2/x', '2026/Februar/x', '2026/Feb/x', '2026/02. Februar/x'] as $vorlage) {
            $pfad = str_replace('x', 'Bauhaus 03.02.2026.pdf', $vorlage);
            self::assertSame([], $this->analysiere($pfad)->hinweise, $pfad);
        }
    }

    public function testFoldersAloneNeverGiveADate(): void
    {
        $v = $this->analysiere('2026/02. Februar/Scan0001.pdf');

        self::assertNull($v->datum);
        self::assertNull($v->lieferant);
        self::assertFalse($v->musterPasst);
        self::assertStringContainsString('passt nicht zum Muster', $v->hinweise[0]);
    }

    public function testACategoryFolderPrefillsTheCategory(): void
    {
        self::assertSame(3, $this->analysiere('2026/sportgeräte/Bauhaus 03.02.2026.pdf')->kategorieId);
        self::assertSame(4, $this->analysiere('Getränke/2026/Kasse/Kiosk 03.02.2026.pdf')->kategorieId);
        self::assertNull($this->analysiere('Bauhaus/2026/Bauhaus 03.02.2026.pdf')->kategorieId);
        self::assertNull($this->analysiere(PfadMuster::OHNE_KATEGORIE . '/Bauhaus 03.02.2026.pdf')->kategorieId);
    }

    public function testTheDeepestCategoryFolderWins(): void
    {
        self::assertSame(4, $this->analysiere('Sportgeräte/Getränke/Bauhaus 03.02.2026.pdf')->kategorieId);
    }

    public function testDirectionFoldersPickTheDirectionAndItsCategories(): void
    {
        $einnahme = $this->analysiere('Einnahmen/Mitgliedsbeiträge/Max Mustermann 03.02.2026.pdf');
        $ausgabe = $this->analysiere('Ausgaben/Mitgliedsbeiträge/Bauhaus 03.02.2026.pdf');

        self::assertSame(InvoiceDirection::Einnahme, $einnahme->richtung);
        self::assertTrue($einnahme->richtungAusOrdner);
        self::assertSame(7, $einnahme->kategorieId);
        self::assertSame(InvoiceDirection::Ausgabe, $ausgabe->richtung);
        // An income category is no expense category.
        self::assertNull($ausgabe->kategorieId);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function uebersprungen(): iterable
    {
        yield 'export originals' => ['Belege_2026/_Originale/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.jpg', false];
        yield 'macOS resource fork' => ['__MACOSX/2026/._Bauhaus 03.02.2026.pdf', true];
        yield 'hidden file' => ['2026/.DS_Store', true];
        yield 'Windows thumbnails' => ['2026/Thumbs.db', true];
        yield 'desktop.ini' => ['2026/desktop.ini', true];
    }

    #[DataProvider('uebersprungen')]
    public function testCopiesAndSystemFilesAreSkipped(string $pfad, bool $system): void
    {
        $v = $this->analysiere($pfad);

        self::assertNotNull($v->ueberspringen);
        self::assertSame($system, $v->systemdatei);
        self::assertFalse($v->hatWerte());
    }

    public function testTheConfiguredPatternIsUsed(): void
    {
        $v = ArchivPfad::analysieren('2026/2026-02-03 Bauhaus.pdf', DateinamenMuster::parse('{datum_iso} {lieferant}'), self::AUSGABE, self::EINNAHME, new \DateTimeImmutable('2026-10-14'));

        self::assertSame('Bauhaus', $v->lieferant);
        self::assertSame('2026-02-03', $v->datum?->format('Y-m-d'));
    }

    private function analysiere(string $pfad): ArchivVorbelegung
    {
        return ArchivPfad::analysieren($pfad, DateinamenMuster::standard(), self::AUSGABE, self::EINNAHME, new \DateTimeImmutable('2026-10-14'));
    }
}
