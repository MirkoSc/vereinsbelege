<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Domain\InvoiceDirection;
use App\Service\Export\BelegPfadDaten;
use App\Service\Export\Dateiname;
use App\Service\Export\PfadMuster;
use App\Service\Export\UngueltigesPfadMuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The export path pattern (issue #75/M12-1, docs/spec/
 * 05-auswertung-und-export.md section 2 and "Pflicht-Tests": ZIP-
 * Pfadbildung - Muster-Platzhalter, verbotene Zeichen, `_Ohne Lieferant`).
 * Collisions: PfadVergabeTest. All names are made up.
 */
final class PfadMusterTest extends TestCase
{
    public function testTheDefaultPatternGivesSupplierYearAndMonthFolders(): void
    {
        $pfad = PfadMuster::standard()->aufloesen($this->beleg(lieferant: 'Musterbau GmbH', datum: '2026-05-17'));

        self::assertSame(['Musterbau GmbH', '2026', '05. Mai', 'Musterbau GmbH 17.05.2026'], $pfad);
    }

    public function testTheArchivePatternPutsTheMonthFirst(): void
    {
        $pfad = PfadMuster::parse(PfadMuster::ARCHIV)->aufloesen($this->beleg(lieferant: 'Stadtwerke Musterstadt', datum: '2026-01-15'));

        self::assertSame(['2026', '01. Januar', 'Stadtwerke Musterstadt 15.01.2026'], $pfad);
    }

    public function testTheCashFolderAppearsOnlyForCashReceipts(): void
    {
        $muster = PfadMuster::parse(PfadMuster::ARCHIV_KASSE);

        self::assertSame(
            ['2026', '03. März', 'Kasse', 'Musterbäckerei 20.03.2026'],
            $muster->aufloesen($this->beleg(lieferant: 'Musterbäckerei', datum: '2026-03-20', kasse: true)),
        );
        self::assertSame(
            ['2026', '03. März', 'Musterbäckerei 20.03.2026'],
            $muster->aufloesen($this->beleg(lieferant: 'Musterbäckerei', datum: '2026-03-20')),
            'an empty {kasse} drops its level',
        );
    }

    public function testEveryPlaceholderResolves(): void
    {
        $muster = PfadMuster::parse('{richtung}/{kategorie}/{jahr}-{monat}/{datum_iso} {lieferant} {nr} {betrag} {monatsname} {datum} [{kasse}].pdf');
        $beleg = new BelegPfadDaten(
            datum: new \DateTimeImmutable('2026-12-01'),
            richtung: InvoiceDirection::Einnahme,
            lieferant: 'Beispiel e. V.',
            kategorie: 'Spenden',
            nr: 'S-7',
            betragCent: 123456,
            kasse: true,
        );

        self::assertSame(
            ['Einnahmen', 'Spenden', '2026-12', '2026-12-01 Beispiel e. V. S-7 1.234,56 12. Dezember 01.12.2026 [Kasse]'],
            $muster->aufloesen($beleg),
        );
    }

    public function testEveryPlaceholderTheAdminPageListsIsKnown(): void
    {
        $alle = implode(' ', array_map(static fn(string $name): string => '{' . $name . '}', array_keys(PfadMuster::PLATZHALTER)));
        $werte = PfadMuster::werte($this->beleg());

        self::assertSame(array_keys(PfadMuster::PLATZHALTER), array_keys($werte));
        self::assertNotSame([], PfadMuster::parse($alle)->aufloesen($this->beleg()));
    }

    public function testAMissingSupplierGoesToTheOhneLieferantFolder(): void
    {
        $pfad = PfadMuster::standard()->aufloesen($this->beleg(lieferant: null, datum: '2026-02-03'));

        self::assertSame(['_Ohne Lieferant', '2026', '02. Februar', '_Ohne Lieferant 03.02.2026'], $pfad);
        self::assertSame('_Ohne Lieferant', PfadMuster::standard()->aufloesen($this->beleg(lieferant: '   '))[0], 'blank counts as missing');
    }

    public function testAMissingCategoryHasItsOwnFolder(): void
    {
        self::assertSame('_Ohne Kategorie', PfadMuster::parse('{kategorie}/{nr}')->aufloesen($this->beleg(kategorie: null))[0]);
    }

    public function testAnEmptyFileNameFallsBackToBeleg(): void
    {
        self::assertSame(['2026', 'Beleg'], PfadMuster::parse('{jahr}/{nr}.pdf')->aufloesen($this->beleg(nr: '')));
    }

    public function testForbiddenCharactersInValuesBecomeDashesAndNeverOpenALevel(): void
    {
        $pfad = PfadMuster::standard()->aufloesen($this->beleg(lieferant: 'A/B\\C:D*E?F"G<H>I|J', datum: '2026-05-17'));

        self::assertSame(['A-B-C-D-E-F-G-H-I-J', '2026', '05. Mai', 'A-B-C-D-E-F-G-H-I-J 17.05.2026'], $pfad);
    }

    public function testUmlautsStay(): void
    {
        self::assertSame('Grün & Söhne – Bäckerei', PfadMuster::standard()->aufloesen($this->beleg(lieferant: 'Grün & Söhne – Bäckerei'))[0]);
    }

    public function testControlCharactersAndRunsOfWhitespaceAreCleaned(): void
    {
        self::assertSame('Muster - Bau', PfadMuster::parse('{lieferant}')->aufloesen($this->beleg(lieferant: "Muster\t\n\x00 Bau"))[0]);
    }

    public function testASupplierNamedDotDotCannotClimbOutOfTheFolder(): void
    {
        $pfad = PfadMuster::standard()->aufloesen($this->beleg(lieferant: '..', datum: '2026-05-17'));

        self::assertNotContains('..', $pfad);
        self::assertNotContains('.', $pfad);
        foreach ($pfad as $segment) {
            self::assertStringNotContainsString('/', $segment);
            self::assertNotSame('', $segment);
        }
        self::assertSame(['2026', '05. Mai', '.. 17.05.2026'], $pfad, 'the folder level is dropped, the file name keeps its date');
    }

    public function testNamesAreCappedInLength(): void
    {
        $pfad = PfadMuster::standard()->aufloesen($this->beleg(lieferant: str_repeat('Ä', 200)));

        self::assertSame(Dateiname::MAX_LAENGE, mb_strlen($pfad[0]));
        self::assertSame(Dateiname::MAX_LAENGE, mb_strlen($pfad[3]));
    }

    public function testWindowsDeviceNamesGetAnUnderscore(): void
    {
        self::assertSame('con_', PfadMuster::parse('{lieferant}/{nr}')->aufloesen($this->beleg(lieferant: 'con'))[0]);
        self::assertSame('NUL_.v1', Dateiname::segment('NUL.v1'));
        self::assertSame('LPT1_', Dateiname::segment('LPT1'));
        self::assertSame('Console', Dateiname::segment('Console'));
    }

    public function testNegativeAmountsAndOtherCurrencies(): void
    {
        $muster = PfadMuster::parse('{betrag}');

        self::assertSame(['-0,05'], $muster->aufloesen($this->beleg(betragCent: -5)));
        self::assertSame(['12,00 USD'], $muster->aufloesen($this->beleg(betragCent: 1200, waehrung: 'usd')));
        self::assertSame(['1.000.000,00'], $muster->aufloesen($this->beleg(betragCent: 100000000)));
    }

    public function testATrailingPdfIsOptional(): void
    {
        $beleg = $this->beleg(lieferant: 'Musterbau GmbH', datum: '2026-05-17');

        self::assertSame(
            PfadMuster::parse('{jahr}/{lieferant} {datum}.PDF')->aufloesen($beleg),
            PfadMuster::parse('{jahr}/{lieferant} {datum}')->aufloesen($beleg),
        );
    }

    public function testSpacesInsidePlaceholderBracesAreTolerated(): void
    {
        self::assertSame(['2026'], PfadMuster::parse('{ jahr }')->aufloesen($this->beleg()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ungueltigeMuster(): iterable
    {
        yield 'empty' => ['', 'Bitte ein Muster angeben'];
        yield 'unknown placeholder' => ['{jahr}/{name}.pdf', 'Unbekannter Platzhalter „{name}“'];
        yield 'lone brace' => ['{jahr/x.pdf', 'Geschweifte Klammern'];
        yield 'nested braces' => ['{{jahr}}', 'Geschweifte Klammern'];
        yield 'backslash' => ['{jahr}\\{lieferant}', '„/“'];
        yield 'leading slash' => ['/{jahr}/{lieferant}', 'nicht mit „/“ beginnen'];
        yield 'trailing slash' => ['{jahr}/', 'nicht mit „/“ beginnen oder enden'];
        yield 'empty level' => ['{jahr}//{lieferant}', 'leere Ebene'];
        yield 'empty file name' => ['{jahr}/.pdf', 'Dateiname'];
        yield 'dot dot' => ['../{lieferant}', '„..“'];
        yield 'dot' => ['./{lieferant}', '„..“'];
        yield 'forbidden character' => ['{jahr}: {lieferant}', 'verboten'];
        yield 'too long' => [str_repeat('x', PfadMuster::MAX_LAENGE + 1), 'zu lang'];
        yield 'broken utf-8' => ["{jahr}/\xC3", 'ungültige Zeichen'];
    }

    #[DataProvider('ungueltigeMuster')]
    public function testInvalidPatternsAreRejectedWithAGermanMessage(string $muster, string $meldung): void
    {
        $this->expectException(UngueltigesPfadMuster::class);
        $this->expectExceptionMessage($meldung);

        PfadMuster::parse($muster);
    }

    public function testTheBundledPatternsAreValid(): void
    {
        foreach (PfadMuster::VORLAGEN as $muster) {
            self::assertSame($muster, PfadMuster::parse($muster)->muster);
        }
        self::assertSame(PfadMuster::STANDARD, array_values(PfadMuster::VORLAGEN)[0], 'the default comes first');
    }

    private function beleg(
        ?string $lieferant = 'Musterbau GmbH',
        string $datum = '2026-05-17',
        bool $kasse = false,
        ?string $kategorie = 'Material',
        string $nr = 'R-1001',
        int $betragCent = 4999,
        string $waehrung = 'EUR',
    ): BelegPfadDaten {
        return new BelegPfadDaten(
            datum: new \DateTimeImmutable($datum),
            richtung: InvoiceDirection::Ausgabe,
            lieferant: $lieferant,
            kategorie: $kategorie,
            nr: $nr,
            betragCent: $betragCent,
            waehrung: $waehrung,
            kasse: $kasse,
        );
    }
}
