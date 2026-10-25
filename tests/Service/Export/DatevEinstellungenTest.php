<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Service\Export\DatevEinstellungen;
use App\Service\Export\DatevZeitraum;
use App\Service\Export\UngueltigeDatevEinstellung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The settings form of /admin/datev and the period of the DATEV export
 * (issue #93, E-49). Saving and reading back runs against the database in
 * tests/Integration/DatevExportFlowTest.php.
 */
final class DatevEinstellungenTest extends TestCase
{
    public function testTheFormIsReadAndOnlyKnownIdsAreKept(): void
    {
        $e = DatevEinstellungen::ausFormular([
            'beraternummer' => ' 29098 ',
            'mandantennummer' => '55003',
            'sachkontenlaenge' => '4',
            'kontenrahmen' => '49',
            'klaerungskonto' => '9999',
            'kategorie' => ['3' => '2110', '5' => '', '77' => '4000'],
            'konto' => ['1' => '1800', '2' => '1000'],
        ], [3, 5], [1, 2]);

        self::assertSame('29098', $e->beraternummer);
        self::assertSame([3 => '2110'], $e->kategorien, 'empty = not mapped, unknown id dropped');
        self::assertSame([1 => '1800', 2 => '1000'], $e->konten);
        self::assertTrue($e->istVollstaendig());
        self::assertSame('2110', $e->gegenkonto(3));
        self::assertNull($e->gegenkonto(5));
        self::assertSame('9999', $e->gegenkonto(null), 'no category: the clearing account');
        self::assertSame('1800', $e->finanzkonto(1));
        self::assertNull($e->finanzkonto(9));
    }

    public function testWithoutAdvisorAndClientNumberThereIsNoHeader(): void
    {
        $e = DatevEinstellungen::ausFormular(['sachkontenlaenge' => '4'], [], []);

        self::assertFalse($e->istVollstaendig());
        self::assertNull($e->kopf(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));
        self::assertNull($e->gegenkonto(null), 'no clearing account set');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function falscheEingaben(): iterable
    {
        $gut = ['beraternummer' => '29098', 'mandantennummer' => '55003', 'sachkontenlaenge' => '4'];
        yield 'Berater zu klein' => [[...$gut, 'beraternummer' => '1000'], 'beraternummer'];
        yield 'Berater zu lang' => [[...$gut, 'beraternummer' => '12345678'], 'beraternummer'];
        yield 'Berater nicht numerisch' => [[...$gut, 'beraternummer' => '29O98'], 'beraternummer'];
        yield 'Mandant 0' => [[...$gut, 'mandantennummer' => '0'], 'mandantennummer'];
        yield 'Mandant zu lang' => [[...$gut, 'mandantennummer' => '123456'], 'mandantennummer'];
        yield 'Sachkontenlänge 3' => [[...$gut, 'sachkontenlaenge' => '3'], 'sachkontenlaenge'];
        yield 'Sachkontenlänge 9' => [[...$gut, 'sachkontenlaenge' => '9'], 'sachkontenlaenge'];
        yield 'Kontenrahmen' => [[...$gut, 'kontenrahmen' => 'SKR'], 'kontenrahmen'];
        yield 'Konto zu lang' => [[...$gut, 'kategorie' => ['3' => '21100']], 'kategorie[3]'];
        yield 'Konto 0' => [[...$gut, 'konto' => ['1' => '0000']], 'konto[1]'];
        yield 'Konto mit Buchstabe' => [[...$gut, 'konto' => ['1' => '18a0']], 'konto[1]'];
        yield 'Klärungskonto' => [[...$gut, 'klaerungskonto' => '-1'], 'klaerungskonto'];
    }

    /** @param array<string, mixed> $felder */
    #[DataProvider('falscheEingaben')]
    public function testWrongValuesAreRefusedWithTheirField(array $felder, string $feld): void
    {
        try {
            DatevEinstellungen::ausFormular($felder, [3], [1]);
            self::fail('accepted');
        } catch (UngueltigeDatevEinstellung $e) {
            self::assertSame($feld, $e->feld);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testALongerLedgerAccountLengthAllowsLongerAccounts(): void
    {
        $e = DatevEinstellungen::ausFormular(['sachkontenlaenge' => '6', 'kategorie' => ['3' => '211000']], [3], []);

        self::assertSame([3 => '211000'], $e->kategorien);
    }

    public function testThePeriodDefaultsToTheCurrentYear(): void
    {
        $z = DatevZeitraum::ausFeldern([], new \DateTimeImmutable('2026-10-25 12:00'));

        self::assertSame(['von' => '2026-01-01', 'bis' => '2026-12-31'], $z->alsFelder());
        self::assertNull($z->fehler());
        self::assertSame('EXTF_Buchungsstapel_2026.csv', $z->dateiname());
    }

    public function testAPeriodWithinOneYearIsTakenOthersAreRefused(): void
    {
        $heute = new \DateTimeImmutable('2026-10-25');
        $quartal = DatevZeitraum::ausFeldern(['von' => '2026-01-01', 'bis' => '2026-03-31'], $heute);

        self::assertNull($quartal->fehler());
        self::assertSame('EXTF_Buchungsstapel_2026-01-01_bis_2026-03-31.csv', $quartal->dateiname());
        self::assertNotNull(DatevZeitraum::ausFeldern(['von' => '2025-12-01', 'bis' => '2026-01-31'], $heute)->fehler(), 'two financial years');
        self::assertNotNull(DatevZeitraum::ausFeldern(['von' => '2026-04-01', 'bis' => '2026-03-01'], $heute)->fehler(), 'reversed');
        self::assertNotNull(DatevZeitraum::ausFeldern(['von' => '2026-02-30', 'bis' => '2026-03-01'], $heute)->fehler(), 'no such day');
        self::assertNotNull(DatevZeitraum::ausFeldern(['von' => '', 'bis' => '2026-03-01'], $heute)->fehler(), 'empty');
        self::assertNotNull(DatevZeitraum::ausFeldern(['von' => ['x'], 'bis' => '2026-03-01'], $heute)->fehler(), 'not a string');
    }
}
