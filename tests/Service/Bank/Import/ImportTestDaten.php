<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank\Import;

use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvStandardprofile;
use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\ImportPosten;
use App\Service\Bank\Umsatz;
use App\Service\Bank\Umsatzdetails;

/**
 * Shared test data for the statement import (issue #62/M9-4): the fixture
 * files of M9-2/M9-3 and hand-made postings.
 */
final class ImportTestDaten
{
    public const string MT940 = __DIR__ . '/../../../fixtures/mt940/';

    public const string CSV = __DIR__ . '/../../../fixtures/bank/';

    public const int SPARKASSE_ID = 1;

    public const int VR_BANK_ID = 2;

    public static function datei(string $pfad): string
    {
        return (string) file_get_contents($pfad);
    }

    /**
     * The shipped profiles with the ids the seed of migration 022 gives them.
     *
     * @return list<CsvProfil>
     */
    public static function profile(): array
    {
        return [
            self::mitId(CsvStandardprofile::sparkasse(), self::SPARKASSE_ID),
            self::mitId(CsvStandardprofile::vrBank(), self::VR_BANK_ID),
        ];
    }

    public static function mitId(CsvProfil $p, int $id): CsvProfil
    {
        return new CsvProfil($id, $p->name, $p->mitgeliefert, $p->trennzeichen, $p->zeichensatz, $p->datumsformat, $p->dezimaltrenner, $p->zuordnung, $p->kopfSignatur);
    }

    public static function posten(
        int $nr,
        string $datum,
        int $cent,
        string $zweck = 'Miete',
        ?string $iban = 'DE89370400440532013000',
        ?int $saldoNach = null,
    ): ImportPosten {
        $tag = new \DateTimeImmutable($datum);

        return new ImportPosten(
            $nr,
            new Umsatz($tag, $tag, $cent, false, '', 'NONREF', null, null, new Umsatzdetails(
                strukturiert: false,
                gvc: null,
                buchungstext: null,
                primanota: null,
                verwendungszweck: $zweck,
                verwendungszweckRoh: $zweck,
                sepa: [],
                bic: null,
                iban: $iban,
                name: 'Muster',
                textschluesselergaenzung: null,
            )),
            'EUR',
            $saldoNach,
            $nr + 2,
        );
    }

    public static function csvFormat(): ImportFormat
    {
        return ImportFormat::csv(self::VR_BANK_ID);
    }
}
