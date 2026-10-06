<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Domain\InvoiceDirection;
use App\Service\Export\BelegPfadDaten;
use App\Service\Export\Dateiname;
use App\Service\Export\ExportEinstellungen;
use App\Service\Export\PfadMuster;
use App\Service\Export\PfadVergabe;
use PHPUnit\Framework\TestCase;

/**
 * Collision-free names inside one export ZIP (issue #75/M12-1, docs/spec/
 * 05-auswertung-und-export.md "Pflicht-Tests": Kollisionssuffix). All names
 * are made up.
 */
final class PfadVergabeTest extends TestCase
{
    public function testTheSpecExampleTreeComesOutAsDocumented(): void
    {
        $muster = PfadMuster::standard();
        $vergabe = new PfadVergabe();
        $wurzel = ExportEinstellungen::wurzelordner(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        $pfade = array_map(
            static fn(array $beleg): string => $vergabe->vergeben([$wurzel, ...$muster->aufloesen(new BelegPfadDaten(
                new \DateTimeImmutable($beleg[1]),
                InvoiceDirection::Ausgabe,
                $beleg[0],
            ))], 'pdf'),
            [
                ['Bauhaus', '2026-02-03'],
                ['Bauhaus', '2026-05-17'],
                ['Bauhaus', '2026-05-17'],
                ['Stadtwerke Musterstadt', '2026-01-15'],
                [null, '2026-03-20'],
            ],
        );

        self::assertSame([
            'Belege_2026/Bauhaus/2026/02. Februar/Bauhaus 03.02.2026.pdf',
            'Belege_2026/Bauhaus/2026/05. Mai/Bauhaus 17.05.2026.pdf',
            'Belege_2026/Bauhaus/2026/05. Mai/Bauhaus 17.05.2026 (2).pdf',
            'Belege_2026/Stadtwerke Musterstadt/2026/01. Januar/Stadtwerke Musterstadt 15.01.2026.pdf',
            'Belege_2026/_Ohne Lieferant/2026/03. März/_Ohne Lieferant 20.03.2026.pdf',
        ], $pfade);
    }

    public function testTheSuffixCountsOn(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('a/x.pdf', $vergabe->vergeben(['a', 'x'], 'pdf'));
        self::assertSame('a/x (2).pdf', $vergabe->vergeben(['a', 'x'], 'pdf'));
        self::assertSame('a/x (3).pdf', $vergabe->vergeben(['a', 'x'], 'pdf'));
        self::assertSame('b/x.pdf', $vergabe->vergeben(['b', 'x'], 'pdf'), 'another folder is no collision');
        self::assertSame('a/x.jpg', $vergabe->vergeben(['a', 'x'], 'jpg'), 'another extension is no collision');
    }

    public function testANameThatAlreadyEndsLikeASuffixStillGetsAFreeOne(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('x (2).pdf', $vergabe->vergeben(['x (2)'], 'pdf'));
        self::assertSame('x.pdf', $vergabe->vergeben(['x'], 'pdf'));
        self::assertSame('x (3).pdf', $vergabe->vergeben(['x'], 'pdf'), '(2) is taken by the first file');
    }

    public function testCollisionsIgnoreCaseAndFoldersKeepTheirFirstSpelling(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('Musterbau/R.pdf', $vergabe->vergeben(['Musterbau', 'R'], 'pdf'));
        self::assertSame('Musterbau/r (2).pdf', $vergabe->vergeben(['MUSTERBAU', 'r'], 'PDF'));
    }

    public function testTheSuffixFitsEvenIntoAMaximumLengthName(): void
    {
        $vergabe = new PfadVergabe();
        $lang = str_repeat('ß', 200);

        $erster = $vergabe->vergeben([$lang], 'pdf');
        $zweiter = $vergabe->vergeben([$lang], 'pdf');

        self::assertSame(str_repeat('ß', Dateiname::MAX_LAENGE) . '.pdf', $erster);
        self::assertSame(str_repeat('ß', Dateiname::MAX_LAENGE - 4) . ' (2).pdf', $zweiter);
        self::assertNotSame($erster, $zweiter);
    }

    public function testSegmentsAreCleanedAgainAndEmptyOnesDropped(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('_Originale/a-b/Beleg.pdf', $vergabe->vergeben(['_Originale', '', 'a/b', '..', ''], '.pdf'));
    }

    public function testAFileNeverTakesTheNameOfAFolder(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('x/y.pdf', $vergabe->vergeben(['x', 'y'], 'pdf'));
        self::assertSame('x (2)', $vergabe->vergeben(['x'], ''));
    }

    public function testTheExtensionIsCleaned(): void
    {
        $vergabe = new PfadVergabe();

        self::assertSame('a.jpeg', $vergabe->vergeben(['a'], '.JPEG'));
        self::assertSame('b', $vergabe->vergeben(['b'], ''));
        self::assertSame('c.pdf', $vergabe->vergeben(['c'], '../pdf'));
    }

    public function testTheSameOrderGivesTheSameNames(): void
    {
        $runde = static function (): array {
            $vergabe = new PfadVergabe();

            return [
                $vergabe->vergeben(['a', 'x'], 'pdf'),
                $vergabe->vergeben(['a', 'x'], 'pdf'),
            ];
        };

        self::assertSame($runde(), $runde());
    }

    public function testTheRootFolderNamesOnlyThePeriod(): void
    {
        self::assertSame('Belege_2026', ExportEinstellungen::wurzelordner(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        self::assertSame(
            'Belege_2026-01-01_bis_2026-03-31',
            ExportEinstellungen::wurzelordner(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31')),
        );
        self::assertSame(
            'Belege_2025-01-01_bis_2026-12-31',
            ExportEinstellungen::wurzelordner(new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-12-31')),
        );
    }
}
