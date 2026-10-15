<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiv;

use App\Service\Archiv\TextQuelle;
use App\Service\Archiv\UngueltigesZip;
use App\Service\Archiv\ZipEintrag;
use App\Service\Archiv\ZipEintragDefekt;
use App\Service\Archiv\ZipLeser;
use App\Service\Export\ZipStrom;
use PHPUnit\Framework\TestCase;

/**
 * The ZIP reader of the archive import (issue #77/M12-3): what real tools
 * write (ZipArchive/libzip, our own streaming writer with data descriptors,
 * hand-built entries for the Windows and macOS habits) and what it refuses.
 * All names are made up.
 */
final class ZipLeserTest extends TestCase
{
    public function testItReadsAZipWrittenByLibzipStoredAndDeflated(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('Beleg-Inhalt ', 5000);
        $zip = self::libzip([
            '2026/02. Februar/Bauhaus 03.02.2026.pdf' => [$pdf, \ZipArchive::CM_DEFLATE],
            '2026/Kasse/Kiosk 03.02.2026.pdf' => ['%PDF-1.4 klein', \ZipArchive::CM_STORE],
        ], ordner: ['2026/']);

        $leser = new ZipLeser(new TextQuelle($zip));
        $eintraege = $leser->verzeichnis();

        $dateien = array_values(array_filter($eintraege, static fn(ZipEintrag $e): bool => !$e->ordner));
        self::assertSame(['2026/02. Februar/Bauhaus 03.02.2026.pdf', '2026/Kasse/Kiosk 03.02.2026.pdf'], array_map(static fn(ZipEintrag $e): string => $e->pfad, $dateien));
        self::assertSame('Bauhaus 03.02.2026.pdf', $dateien[0]->dateiname());
        self::assertSame($pdf, self::inhalt($leser, $dateien[0]));
        self::assertSame('%PDF-1.4 klein', self::inhalt($leser, $dateien[1]));
        self::assertCount(1, array_filter($eintraege, static fn(ZipEintrag $e): bool => $e->ordner));
    }

    public function testItReadsOurOwnStreamingExportWithDataDescriptors(): void
    {
        $strom = new ZipStrom(new \DateTimeImmutable('2026-02-03 10:00'));
        $zip = '';
        foreach ($strom->datei('Belege_2026/index.csv', ["Datum;Lieferant\r\n"]) as $teil) {
            $zip .= $teil;
        }
        $gross = random_bytes(200000);
        foreach ($strom->datei('Belege_2026/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf', [substr($gross, 0, 70000), substr($gross, 70000)], 1) as $teil) {
            $zip .= $teil;
        }
        $zip .= $strom->abschluss();

        $leser = new ZipLeser(new TextQuelle($zip));
        $eintraege = $leser->verzeichnis();

        self::assertSame('Belege_2026/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf', $eintraege[1]->pfad);
        self::assertSame($gross, self::inhalt($leser, $eintraege[1]));
    }

    public function testNamesWithoutTheUtf8FlagAreReadAsCp850(): void
    {
        // German Windows' "compressed folder": OEM code page, no flag.
        $roh = "Getr\x84nke M\x81ller 03.02.2026.pdf";
        $zip = self::roh([['name' => $roh, 'inhalt' => 'x']]);

        self::assertSame('Getränke Müller 03.02.2026.pdf', (new ZipLeser(new TextQuelle($zip)))->verzeichnis()[0]->pfad);
    }

    public function testDecomposedUtf8FromMacOsBecomesComposed(): void
    {
        $zip = self::roh([['name' => "Getra\u{0308}nke Mu\u{0308}ller 03.02.2026.pdf", 'inhalt' => 'x']]);

        self::assertSame('Getränke Müller 03.02.2026.pdf', (new ZipLeser(new TextQuelle($zip)))->verzeichnis()[0]->pfad);
    }

    public function testTheUnicodePathFieldWinsWhenItsChecksumFits(): void
    {
        $roh = 'Getr?nke 03.02.2026.pdf';
        $passend = pack('vvC', 0x7075, 5 + strlen('Getränke 03.02.2026.pdf'), 1) . pack('V', crc32($roh)) . 'Getränke 03.02.2026.pdf';
        $veraltet = pack('vvC', 0x7075, 5 + strlen('Anders 03.02.2026.pdf'), 1) . pack('V', crc32('alt')) . 'Anders 03.02.2026.pdf';
        $zip = self::roh([
            ['name' => $roh, 'inhalt' => 'x', 'extra' => $passend],
            ['name' => $roh, 'inhalt' => 'y', 'extra' => $veraltet],
        ]);

        $eintraege = (new ZipLeser(new TextQuelle($zip)))->verzeichnis();

        self::assertSame('Getränke 03.02.2026.pdf', $eintraege[0]->pfad);
        self::assertSame($roh, $eintraege[1]->pfad);
    }

    public function testBackslashesEmptyAndDotLevelsAreNormalised(): void
    {
        $zip = self::roh([['name' => 'Belege\\2026\\.\\\\Bauhaus 03.02.2026.pdf', 'inhalt' => 'x', 'flags' => 0x800]]);

        $eintrag = (new ZipLeser(new TextQuelle($zip)))->verzeichnis()[0];

        self::assertSame('Belege/2026/Bauhaus 03.02.2026.pdf', $eintrag->pfad);
        self::assertNull($eintrag->problem);
    }

    public function testDangerousOrUnreadableEntriesCarryAProblem(): void
    {
        $zip = self::roh([
            ['name' => '../../etc/Bauhaus 03.02.2026.pdf', 'inhalt' => 'x'],
            ['name' => '/abs/Bauhaus 03.02.2026.pdf', 'inhalt' => 'x'],
            ['name' => 'C:/Bauhaus 03.02.2026.pdf', 'inhalt' => 'x'],
            ['name' => 'geheim.pdf', 'inhalt' => 'x', 'flags' => 0x1],
            ['name' => 'bzip.pdf', 'inhalt' => 'x', 'methode' => 12],
            ['name' => 'gut.pdf', 'inhalt' => 'x'],
        ]);
        $leser = new ZipLeser(new TextQuelle($zip));
        $eintraege = $leser->verzeichnis();

        self::assertStringContainsString('„..“', (string) $eintraege[0]->problem);
        self::assertStringContainsString('absolut', (string) $eintraege[1]->problem);
        self::assertStringContainsString('absolut', (string) $eintraege[2]->problem);
        self::assertStringContainsString('verschlüsselt', (string) $eintraege[3]->problem);
        self::assertStringContainsString('Verfahren', (string) $eintraege[4]->problem);
        self::assertNull($eintraege[5]->problem);

        $this->expectException(ZipEintragDefekt::class);
        self::inhalt($leser, $eintraege[3]);
    }

    public function testAWrongChecksumIsNoticedAtTheEndOfTheEntry(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => '%PDF-1.4 Inhalt', 'crc' => 12345]]);
        $leser = new ZipLeser(new TextQuelle($zip));

        $this->expectException(ZipEintragDefekt::class);
        $this->expectExceptionMessage('Prüfsumme');
        self::inhalt($leser, $leser->verzeichnis()[0]);
    }

    public function testMoreThanAnnouncedStopsEarly(): void
    {
        // A deflated bomb: 4 MiB of zeros announced as 10 bytes.
        $roh = str_repeat("\0", 4 * 1024 * 1024);
        $zip = self::roh([['name' => 'bombe.pdf', 'inhalt' => $roh, 'methode' => 8, 'groesse' => 10]]);
        $leser = new ZipLeser(new TextQuelle($zip));

        $this->expectException(ZipEintragDefekt::class);
        $this->expectExceptionMessage('größer');
        self::inhalt($leser, $leser->verzeichnis()[0]);
    }

    public function testTheSizeLimitAppliesBeforeAByteIsRead(): void
    {
        $zip = self::roh([['name' => 'gross.pdf', 'inhalt' => str_repeat('x', 2000)]]);
        $leser = new ZipLeser(new TextQuelle($zip));

        $this->expectException(ZipEintragDefekt::class);
        $this->expectExceptionMessage('zu groß');
        self::inhalt($leser, $leser->verzeichnis()[0], 1000);
    }

    public function testATruncatedEntryIsDefective(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => str_repeat('x', 100), 'gepackt' => 5000]]);
        $leser = new ZipLeser(new TextQuelle($zip));

        $this->expectException(ZipEintragDefekt::class);
        self::inhalt($leser, $leser->verzeichnis()[0]);
    }

    public function testACommentAfterTheEndRecordIsAllowed(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => 'x']], 'Kommentar mit PK' . pack('V', 0x06054B50) . ' drin');

        self::assertSame('a.pdf', (new ZipLeser(new TextQuelle($zip)))->verzeichnis()[0]->pfad);
    }

    public function testNoZipAtAll(): void
    {
        $this->expectException(UngueltigesZip::class);
        $this->expectExceptionMessage('kein ZIP');

        (new ZipLeser(new TextQuelle("%PDF-1.4\n" . str_repeat('x', 100))))->verzeichnis();
    }

    public function testZip64IsRefusedAsAWhole(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => 'x']]);
        $ende = strrpos($zip, pack('V', 0x06054B50));
        $locator = pack('VVPV', 0x07064B50, 0, 0, 1);
        $zip = substr($zip, 0, (int) $ende) . $locator . substr($zip, (int) $ende);

        $this->expectException(UngueltigesZip::class);
        $this->expectExceptionMessage('ZIP64');

        (new ZipLeser(new TextQuelle($zip)))->verzeichnis();
    }

    public function testSplitArchivesAreRefused(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => 'x']]);
        $ende = (int) strrpos($zip, pack('V', 0x06054B50));
        $zip = substr_replace($zip, pack('v', 1), $ende + 4, 2);

        $this->expectException(UngueltigesZip::class);
        $this->expectExceptionMessage('Mehrteilige');

        (new ZipLeser(new TextQuelle($zip)))->verzeichnis();
    }

    public function testATruncatedCentralDirectoryIsRefused(): void
    {
        $zip = self::roh([['name' => 'a.pdf', 'inhalt' => 'x']]);
        $ende = (int) strrpos($zip, pack('V', 0x06054B50));
        // Claim one more entry than there is.
        $zip = substr_replace($zip, pack('vv', 2, 2), $ende + 8, 4);

        $this->expectException(UngueltigesZip::class);

        (new ZipLeser(new TextQuelle($zip)))->verzeichnis();
    }

    private static function inhalt(ZipLeser $leser, ZipEintrag $eintrag, int $max = ZipLeser::MAX_DATEI): string
    {
        return implode('', iterator_to_array($leser->inhalt($eintrag, $max), false));
    }

    /**
     * @param array<string, array{0: string, 1: int}> $dateien
     * @param list<string> $ordner
     */
    private static function libzip(array $dateien, array $ordner = []): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'ziptest');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pfad, \ZipArchive::OVERWRITE));
        foreach ($ordner as $o) {
            $zip->addEmptyDir($o);
        }
        foreach ($dateien as $name => [$inhalt, $methode]) {
            $zip->addFromString($name, $inhalt);
            $zip->setCompressionName($name, $methode);
        }
        $zip->close();
        $inhalt = (string) file_get_contents($pfad);
        unlink($pfad);

        return $inhalt;
    }

    /**
     * A ZIP built by hand, to write what libzip would not: other name
     * encodings, flags, wrong checksums and sizes.
     *
     * @param list<array{name: string, inhalt: string, flags?: int, methode?: int, extra?: string, crc?: int, groesse?: int, gepackt?: int}> $eintraege
     */
    private static function roh(array $eintraege, string $kommentar = ''): string
    {
        $daten = '';
        $zentral = '';
        foreach ($eintraege as $e) {
            $methode = $e['methode'] ?? 0;
            $gepacktInhalt = $methode === 8 ? (string) gzdeflate($e['inhalt'], 6) : $e['inhalt'];
            $crc = $e['crc'] ?? crc32($e['inhalt']);
            $gepackt = $e['gepackt'] ?? strlen($gepacktInhalt);
            $groesse = $e['groesse'] ?? strlen($e['inhalt']);
            $flags = $e['flags'] ?? 0;
            $extra = $e['extra'] ?? '';
            $offset = strlen($daten);

            $daten .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $methode, 0, 0x5C43, $crc, $gepackt, $groesse, strlen($e['name']), 0)
                . $e['name'] . $gepacktInhalt;
            $zentral .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $methode, 0, 0x5C43, $crc, $gepackt, $groesse, strlen($e['name']), strlen($extra), 0, 0, 0, 0, $offset)
                . $e['name'] . $extra;
        }

        return $daten . $zentral
            . pack('VvvvvVVv', 0x06054B50, 0, 0, count($eintraege), count($eintraege), strlen($zentral), strlen($daten), strlen($kommentar))
            . $kommentar;
    }
}
