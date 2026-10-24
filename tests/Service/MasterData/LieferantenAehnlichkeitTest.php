<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Service\MasterData\LieferantenAehnlichkeit;
use App\Service\MasterData\SupplierKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 5 of the supplier resolution (issue #48/M7-6, docs/spec/
 * 03-erfassung-und-ki.md section 7): names alike enough for a suggestion,
 * compared after the normalisation of step 4. Synthetic names only.
 */
final class LieferantenAehnlichkeitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aehnlich(): iterable
    {
        yield 'umlaut lost by the AI' => ['Getränke Müller GmbH', 'Getraenke Muller'];
        yield 'one letter missing' => ['Vodafone', 'Vodafon'];
        yield 'one letter misread' => ['Sportverein Musterstadt', 'Sportverein Musterstedt'];
        yield 'words swapped (trigrams)' => ['Sporthaus Nord', 'Nord Sporthaus'];
        yield 'only the legal form differs' => ['Stadtwerke Musterstadt', 'Stadtwerke Musterstadt AG'];
    }

    #[DataProvider('aehnlich')]
    public function testAlikeNamesReachTheThreshold(string $a, string $b): void
    {
        $wert = LieferantenAehnlichkeit::wert(SupplierKeys::name($a), SupplierKeys::name($b));

        self::assertGreaterThanOrEqual(LieferantenAehnlichkeit::SCHWELLE, $wert);
        self::assertLessThanOrEqual(1.0, $wert);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function verschieden(): iterable
    {
        yield 'another person' => ['Sport Müller', 'Sport Mayer'];
        yield 'another branch' => ['Sporthaus Nord', 'Sporthaus Süd'];
        yield 'another town' => ['Stadtwerke Musterstadt', 'Stadtwerke Beispielstadt'];
        yield 'a short name is never a typo' => ['REWE', 'REWA'];
        yield 'three letters' => ['OBI', 'ABI'];
        yield 'different shops' => ['Bauhaus', 'Baumarkt'];
    }

    #[DataProvider('verschieden')]
    public function testDifferentNamesStayBelowTheThreshold(string $a, string $b): void
    {
        self::assertLessThan(LieferantenAehnlichkeit::SCHWELLE, LieferantenAehnlichkeit::wert(SupplierKeys::name($a), SupplierKeys::name($b)));
    }

    public function testEqualAndEmptyNames(): void
    {
        self::assertSame(1.0, LieferantenAehnlichkeit::wert('abc', 'abc'), 'equal is equal, however short');
        self::assertSame(0.0, LieferantenAehnlichkeit::wert('', ''));
        self::assertSame(0.0, LieferantenAehnlichkeit::wert('sporthaus', ''));
    }

    public function testItIsSymmetricAndCountsLettersNotBytes(): void
    {
        $a = SupplierKeys::name('Łódź Sport');
        $b = SupplierKeys::name('Lodz Sport');

        self::assertSame(LieferantenAehnlichkeit::wert($a, $b), LieferantenAehnlichkeit::wert($b, $a));
        // Two letters of ten differ: 0.8 by Levenshtein on letters - on bytes
        // the multibyte "ł" and "ó" would count double and miss the threshold.
        self::assertEqualsWithDelta(0.8, LieferantenAehnlichkeit::wert($a, $b), 0.001);
    }
}
