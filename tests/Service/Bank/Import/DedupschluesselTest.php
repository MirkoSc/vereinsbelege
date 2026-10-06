<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank\Import;

use App\Service\Bank\Import\Dedupschluessel;
use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\KontoauszugLeser;
use App\Service\Crypto\BlindIndex;
use PHPUnit\Framework\TestCase;

/**
 * The duplicate key of a booking (issue #62/M9-4, docs/spec/
 * 04-bank-und-abgleich.md section 4 and "Pflicht-Tests": Duplikaterkennung
 * bei überlappendem und doppeltem Import).
 */
final class DedupschluesselTest extends TestCase
{
    private BlindIndex $index;

    protected function setUp(): void
    {
        $this->index = new BlindIndex(random_bytes(BlindIndex::BYTES));
    }

    public function testTheSameFileYieldsTheSameDistinctKeys(): void
    {
        $datei = new KontoauszugLeser()->lies(ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta'), ImportFormat::mt940(), []);

        $erst = Dedupschluessel::fuer($this->index, 3, $datei->posten);
        $dann = Dedupschluessel::fuer($this->index, 3, $datei->posten);

        self::assertSame($erst, $dann);
        self::assertCount(5, array_unique($erst));
        foreach ($erst as $schluessel) {
            self::assertSame(BlindIndex::BYTES, strlen($schluessel));
        }
    }

    public function testIdenticalBookingsOnOneDayAreToldApartByTheirRunningNumber(): void
    {
        $zwei = [ImportTestDaten::posten(0, '2024-03-01', -1000), ImportTestDaten::posten(1, '2024-03-01', -1000)];
        $eine = [ImportTestDaten::posten(0, '2024-03-01', -1000)];

        $beide = Dedupschluessel::fuer($this->index, 1, $zwei);

        self::assertNotSame($beide[0], $beide[1]);
        self::assertSame([$beide[0]], Dedupschluessel::fuer($this->index, 1, $eine), 'A file with one of them meets the first.');
    }

    public function testThePurposeIsComparedWithoutCaseSpacesAndPunctuation(): void
    {
        $a = Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000, 'RE 2024-117 Trikots E-Jugend')]);
        $b = Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000, 're2024117 trikotse jugend')]);

        self::assertSame($a, $b);
        self::assertSame('re2024117trikotsejugendmüller', Dedupschluessel::zweck('RE 2024/117 Trikots E-Jugend, Müller'));
    }

    public function testEveryPartOfTheKeyCounts(): void
    {
        $basis = Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000)])[0];
        $varianten = [
            'konto' => Dedupschluessel::fuer($this->index, 2, [ImportTestDaten::posten(0, '2024-03-01', -1000)])[0],
            'datum' => Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-02', -1000)])[0],
            'betrag' => Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', 1000)])[0],
            'zweck' => Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000, 'Pacht')])[0],
            'iban' => Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000, iban: 'DE02120300000000202051')])[0],
            'ohne iban' => Dedupschluessel::fuer($this->index, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000, iban: null)])[0],
        ];

        foreach ($varianten as $was => $schluessel) {
            self::assertNotSame($basis, $schluessel, $was);
        }
        $andererTresor = new BlindIndex(random_bytes(BlindIndex::BYTES));
        self::assertNotSame($basis, Dedupschluessel::fuer($andererTresor, 1, [ImportTestDaten::posten(0, '2024-03-01', -1000)])[0], 'Another vault gives other keys.');
    }

    public function testAnOverlappingExportSharesItsKeysWithTheFullOne(): void
    {
        $voll = ImportTestDaten::datei(ImportTestDaten::MT940 . 'sparkasse.sta');
        $erster = substr($voll, 0, (int) strpos($voll, "\r\n-\r\n") + 5);
        $leser = new KontoauszugLeser();

        $teil = Dedupschluessel::fuer($this->index, 1, $leser->lies($erster, ImportFormat::mt940(), [])->posten);
        $alle = Dedupschluessel::fuer($this->index, 1, $leser->lies($voll, ImportFormat::mt940(), [])->posten);

        self::assertCount(3, $teil);
        self::assertSame($teil, array_values(array_intersect($alle, $teil)));
    }

    public function testCsvRowsKeepTheirKeysWhenTheExportGrows(): void
    {
        $voll = ImportTestDaten::datei(ImportTestDaten::CSV . 'vrbank.csv');
        $zeilen = preg_split('/(?<=\n)/', $voll) ?: [];
        // The header and the two older rows: an earlier export of the same account.
        $frueher = $zeilen[0] . $zeilen[2] . $zeilen[3];
        $leser = new KontoauszugLeser();

        $teil = Dedupschluessel::fuer($this->index, 1, $leser->lies($frueher, ImportTestDaten::csvFormat(), ImportTestDaten::profile())->posten);
        $alle = Dedupschluessel::fuer($this->index, 1, $leser->lies($voll, ImportTestDaten::csvFormat(), ImportTestDaten::profile())->posten);

        self::assertCount(2, $teil);
        self::assertCount(2, array_intersect($alle, $teil));
    }
}
