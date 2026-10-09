<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Reads a ZIP the strict way, independently of App\Service\Export\ZipStrom
 * and without ext-zip (issue #76/M12-2): the end record, every central
 * directory entry, the local header it points to, the deflated data, the
 * data descriptor - and that all of it lies back to back with nothing in
 * between or left over. Any inconsistency fails the test.
 */
final class ZipLeser
{
    /**
     * @return array<string, string> path => content, in archive order
     */
    public static function inhalte(string $zip): array
    {
        $inhalte = [];
        foreach (self::eintraege($zip) as $eintrag) {
            $inhalte[$eintrag['name']] = $eintrag['inhalt'];
        }

        return $inhalte;
    }

    /**
     * @return list<array{name: string, flags: int, methode: int, inhalt: string}>
     */
    public static function eintraege(string $zip): array
    {
        Assert::assertGreaterThanOrEqual(22, strlen($zip), 'a ZIP has at least its end record');
        $ende = self::lies('Vsignatur/vdisk/vdiskVerzeichnis/vanzahlHier/vanzahl/Vgroesse/Voffset/vkommentar', substr($zip, -22));
        Assert::assertSame(0x06054B50, $ende['signatur'], 'end of central directory record');
        Assert::assertSame($ende['anzahl'], $ende['anzahlHier']);
        Assert::assertSame(0, $ende['kommentar']);
        Assert::assertSame(strlen($zip) - 22, $ende['offset'] + $ende['groesse'], 'the central directory ends where the end record begins');

        $eintraege = [];
        $zentral = $ende['offset'];
        $erwartet = 0;
        for ($i = 0; $i < $ende['anzahl']; $i++) {
            $z = self::lies('Vsignatur/verstellt/vbraucht/vflags/vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vroh/vname/vextra/vkommentar/vdisk/vintern/Vextern/Voffset', substr($zip, $zentral, 46));
            Assert::assertSame(0x02014B50, $z['signatur'], 'central directory entry');
            $name = substr($zip, $zentral + 46, $z['name']);
            $zentral += 46 + $z['name'] + $z['extra'] + $z['kommentar'];

            Assert::assertSame($erwartet, $z['offset'], 'entries lie back to back');
            $l = self::lies('Vsignatur/vbraucht/vflags/vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vroh/vname/vextra', substr($zip, $z['offset'], 30));
            Assert::assertSame(0x04034B50, $l['signatur'], 'local file header');
            Assert::assertSame($name, substr($zip, $z['offset'] + 30, $l['name']), 'same name in both headers');
            Assert::assertSame($z['flags'], $l['flags']);
            Assert::assertSame($z['methode'], $l['methode']);

            $daten = $z['offset'] + 30 + $l['name'] + $l['extra'];
            $gepackt = substr($zip, $daten, $z['gepackt']);
            $inhalt = match ($z['methode']) {
                0 => $gepackt,
                8 => gzinflate($gepackt),
                default => Assert::fail('unknown compression method ' . $z['methode']),
            };
            Assert::assertIsString($inhalt, 'the deflate stream inflates');
            Assert::assertSame($z['roh'], strlen($inhalt), 'uncompressed size');
            Assert::assertSame($z['crc'], crc32($inhalt), 'CRC-32');

            $erwartet = $daten + $z['gepackt'];
            if (($z['flags'] & 0x0008) !== 0) {
                $d = self::lies('Vsignatur/Vcrc/Vgepackt/Vroh', substr($zip, $erwartet, 16));
                Assert::assertSame(0x08074B50, $d['signatur'], 'data descriptor');
                Assert::assertSame([$z['crc'], $z['gepackt'], $z['roh']], [$d['crc'], $d['gepackt'], $d['roh']], 'descriptor matches the directory');
                $erwartet += 16;
            } else {
                Assert::assertSame([$z['crc'], $z['gepackt'], $z['roh']], [$l['crc'], $l['gepackt'], $l['roh']]);
            }

            $eintraege[] = ['name' => $name, 'flags' => $z['flags'], 'methode' => $z['methode'], 'inhalt' => $inhalt];
        }
        Assert::assertSame($ende['offset'], $erwartet, 'nothing between the last entry and the central directory');
        Assert::assertSame($ende['offset'] + $ende['groesse'], $zentral, 'central directory size');

        return $eintraege;
    }

    /**
     * @return array<string, int>
     */
    private static function lies(string $format, string $bytes): array
    {
        $werte = unpack($format, $bytes);
        Assert::assertIsArray($werte, 'record is complete');

        return array_map(intval(...), $werte);
    }
}
