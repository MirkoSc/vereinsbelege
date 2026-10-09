<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Service\Export\ZipStrom;
use App\Tests\Support\ZipLeser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The streaming ZIP writer of the export (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md "Pflicht-Tests": ZIP-Stream ist
 * gültiges ZIP). Checked against an independent strict reader
 * (App\Tests\Support\ZipLeser) and against libzip (ext-zip).
 */
final class ZipStromTest extends TestCase
{
    public function testWritesAValidArchive(): void
    {
        $zip = $this->zip([
            'Belege_2026/index.csv' => ["Datum;Lieferant\r\n", "03.02.2026;Bauhaus\r\n"],
            'Belege_2026/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf' => ['%PDF-1.4 erfunden'],
        ]);

        self::assertSame([
            'Belege_2026/index.csv' => "Datum;Lieferant\r\n03.02.2026;Bauhaus\r\n",
            'Belege_2026/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf' => '%PDF-1.4 erfunden',
        ], ZipLeser::inhalte($zip));

        foreach (ZipLeser::eintraege($zip) as $eintrag) {
            self::assertSame(8, $eintrag['methode'], 'deflate');
            self::assertSame(0x0008, $eintrag['flags'] & 0x0008, 'sizes in the data descriptor');
            self::assertSame(0x0800, $eintrag['flags'] & 0x0800, 'names are UTF-8');
        }
    }

    public function testManyPiecesAndEmptyFilesSurvive(): void
    {
        $stuecke = [];
        for ($i = 0; $i < 200; $i++) {
            $stuecke[] = random_bytes(1 + $i * 37 % 4096);
        }
        $text = str_repeat('Rasendünger Frühjahr ', 5000);

        $zip = $this->zip([
            'zufall.bin' => $stuecke,
            'leer.pdf' => [],
            'auch-leer.txt' => ['', ''],
            'text.txt' => str_split($text, 999),
        ], stufe: 9);

        self::assertSame([
            'zufall.bin' => implode('', $stuecke),
            'leer.pdf' => '',
            'auch-leer.txt' => '',
            'text.txt' => $text,
        ], ZipLeser::inhalte($zip));
        self::assertLessThan(strlen($text) / 10, strlen($zip) - strlen(implode('', $stuecke)), 'text is compressed');
    }

    public function testAnEmptyArchiveIsJustTheEndRecord(): void
    {
        $zip = new ZipStrom(new \DateTimeImmutable('2026-10-08 12:00:00'))->abschluss();

        self::assertSame(22, strlen($zip));
        self::assertSame([], ZipLeser::inhalte($zip));
    }

    public function testUmlautsAreStoredAsUtf8Bytes(): void
    {
        $name = 'Belege_2026/Müller & Söhne/2026/03. März/Bäckerei Groß 01.03.2026.pdf';
        $zip = $this->zip([$name => ['x']]);

        self::assertSame([$name], array_keys(ZipLeser::inhalte($zip)));
        self::assertStringContainsString($name, $zip, 'the name is written as it is, UTF-8');
    }

    /**
     * The source is pulled lazily: the local header leaves before the first
     * byte is decrypted, and each piece leaves before the next is read -
     * nothing is collected.
     */
    public function testPullsTheSourceLazily(): void
    {
        $gelesen = 0;
        $quelle = (static function () use (&$gelesen): \Generator {
            for ($i = 0; $i < 50; $i++) {
                $gelesen++;
                yield random_bytes(65536);
            }
        })();

        $zip = new ZipStrom(new \DateTimeImmutable());
        $strom = $zip->datei('gross.bin', $quelle, 1);

        self::assertSame(0, $gelesen, 'nothing is read before the stream is pulled');
        self::assertSame(0x04034B50, unpack('V', $strom->current())[1], 'the local header comes first');
        self::assertSame(0, $gelesen, 'the header needs no content');

        $ausgegeben = strlen($strom->current());
        $strom->next();
        while ($strom->valid() && $gelesen < 50) {
            $ausgegeben += strlen($strom->current());
            $strom->next();
        }
        self::assertGreaterThan(65536 * 40, $ausgegeben, 'most of the file left before the source was drained');
    }

    public function testCountsWhatWentOut(): void
    {
        $zip = new ZipStrom(new \DateTimeImmutable());
        $bytes = implode('', iterator_to_array($zip->datei('a.txt', ['abc']), false));
        self::assertSame(strlen($bytes), $zip->groesse());

        $bytes .= $zip->abschluss();
        self::assertSame(strlen($bytes), $zip->groesse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltigePfade(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent' => ['Belege_2026/../../x.pdf'];
        yield 'dot' => ['Belege_2026/./x.pdf'];
        yield 'empty level' => ['Belege_2026//x.pdf'];
        yield 'trailing slash' => ['Belege_2026/'];
        yield 'backslash' => ['Belege_2026\\x.pdf'];
        yield 'control character' => ["Belege_2026/x\x00.pdf"];
        yield 'broken UTF-8' => ["Belege_2026/\xC3.pdf"];
    }

    #[DataProvider('ungueltigePfade')]
    public function testRefusesPathsThatCouldEscape(string $pfad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(new ZipStrom(new \DateTimeImmutable())->datei($pfad, ['x']));
    }

    public function testRefusesTheSamePathTwice(): void
    {
        $zip = new ZipStrom(new \DateTimeImmutable());
        iterator_to_array($zip->datei('a.pdf', ['x']));

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($zip->datei('a.pdf', ['y']));
    }

    public function testNothingFollowsTheEndRecord(): void
    {
        $zip = new ZipStrom(new \DateTimeImmutable());
        $zip->abschluss();

        $this->expectException(\LogicException::class);
        iterator_to_array($zip->datei('a.pdf', ['x']));
    }

    /**
     * Interoperability: libzip - what PHP's ZipArchive and many tools use -
     * opens the archive with its consistency check and verifies every CRC
     * while reading.
     */
    #[RequiresPhpExtension('zip')]
    public function testLibzipReadsIt(): void
    {
        $inhalte = [
            'Belege_2026/index.csv' => "\u{FEFF}Datum;Lieferant\r\n",
            'Belege_2026/Müller/2026/01. Januar/Müller 15.01.2026.pdf' => random_bytes(200_000),
            'Belege_2026/_Originale/Müller/2026/01. Januar/Müller 15.01.2026.jpg' => '',
        ];
        $datei = tempnam(sys_get_temp_dir(), 'vb_zipstrom_');
        self::assertIsString($datei);

        try {
            file_put_contents($datei, $this->zip(array_map(static fn(string $inhalt): array => str_split($inhalt, 65536) ?: [], $inhalte)));
            $archiv = new \ZipArchive();
            self::assertTrue($archiv->open($datei, \ZipArchive::CHECKCONS));
            self::assertSame(count($inhalte), $archiv->numFiles);
            foreach ($inhalte as $name => $inhalt) {
                self::assertSame($inhalt, $archiv->getFromName($name), $name);
            }
            $archiv->close();
        } finally {
            unlink($datei);
        }
    }

    /**
     * @param array<string, list<string>> $dateien
     */
    private function zip(array $dateien, int $stufe = 6): string
    {
        $zip = new ZipStrom(new \DateTimeImmutable('2026-10-08 14:37:12'));
        $bytes = '';
        foreach ($dateien as $pfad => $stuecke) {
            foreach ($zip->datei($pfad, $stuecke, $stufe) as $stueck) {
                $bytes .= $stueck;
            }
        }

        return $bytes . $zip->abschluss();
    }
}
