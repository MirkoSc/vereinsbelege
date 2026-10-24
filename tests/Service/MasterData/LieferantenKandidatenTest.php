<?php

declare(strict_types=1);

namespace App\Tests\Service\MasterData;

use App\Domain\SupplierKeyKind;
use App\Service\MasterData\LieferantenKandidaten;
use PHPUnit\Framework\TestCase;

/**
 * What the blind-index preselection of the prompt looks up from a
 * receipt's text (issue #48/M7-6, docs/spec/03-erfassung-und-ki.md section
 * 6): ids and runs of words, normalised like the supplier keys. Synthetic
 * data only (example IBAN, test creditor id).
 */
final class LieferantenKandidatenTest extends TestCase
{
    private const string TEXT = <<<'TEXT'
        Getränke Müller GmbH & Co. KG · Hauptstraße 1 · 12345 Musterstadt
        Rechnung Nr. RE-2026-0815
        USt-IdNr.: DE 123 456 789   Steuernummer 12/345/67890
        Gläubiger-ID DE98ZZZ09999999999
        IBAN DE89 3704 0044 0532 0130 00 BIC COBADEFFXXX
        TEXT;

    public function testIdsAreFoundWrittenAsUsualAndNormalisedLikeTheKeys(): void
    {
        $schluessel = LieferantenKandidaten::aus(self::TEXT);

        self::assertContains([SupplierKeyKind::Iban, 'DE89370400440532013000'], $schluessel, 'blanks gone, the BIC after it not taken along');
        self::assertContains([SupplierKeyKind::VatId, 'DE123456789'], $schluessel);
        self::assertContains([SupplierKeyKind::TaxNumber, '1234567890'], $schluessel);
        self::assertContains([SupplierKeyKind::CreditorId, 'DE98ZZZ09999999999'], $schluessel);
    }

    public function testAnIbanWithAWrongCheckSumIsNoCandidate(): void
    {
        $schluessel = LieferantenKandidaten::aus('IBAN DE89 3704 0044 0532 0130 01');

        self::assertSame([], array_values(array_filter($schluessel, static fn(array $k): bool => $k[0] === SupplierKeyKind::Iban)));
    }

    public function testRunsOfWordsOfALineBecomeNamesWithoutTheirLegalForm(): void
    {
        $namen = self::namen(LieferantenKandidaten::aus(self::TEXT));

        self::assertContains('getraenke mueller', $namen, '"Getränke Müller GmbH & Co. KG" as the key stores it');
        self::assertContains('getraenke', $namen);
        self::assertContains('musterstadt', $namen);
        self::assertNotContains('musterstadt rechnung', $namen, 'no run crosses a line');
        self::assertNotContains('nr', $namen, 'shorter than three letters');
    }

    public function testRunsAreAtMostFourWordsLong(): void
    {
        $namen = self::namen(LieferantenKandidaten::aus('eins zwei drei vier fuenf'));

        self::assertContains('eins zwei drei vier', $namen);
        self::assertNotContains('eins zwei drei vier fuenf', $namen);
    }

    public function testEachKeyOnceIdsFirstAndNothingWithoutText(): void
    {
        $schluessel = LieferantenKandidaten::aus("Sporthaus Nord\nSporthaus Nord\nDE89370400440532013000");

        self::assertSame(SupplierKeyKind::Iban, $schluessel[0][0]);
        self::assertSame(count($schluessel), count(array_unique(array_map(static fn(array $k): string => $k[0]->value . ':' . $k[1], $schluessel))));
        self::assertSame([], LieferantenKandidaten::aus(" \n\f "));
    }

    public function testOnlyTheBeginningOfAVeryLongTextIsLookedAt(): void
    {
        $text = str_repeat('x ', LieferantenKandidaten::TEXT_MAX) . "\nSporthaus Spaet";

        self::assertNotContains('sporthaus spaet', self::namen(LieferantenKandidaten::aus($text)));
    }

    /**
     * @param list<array{SupplierKeyKind, string}> $schluessel
     *
     * @return list<string>
     */
    private static function namen(array $schluessel): array
    {
        return array_values(array_map(
            static fn(array $k): string => $k[1],
            array_filter($schluessel, static fn(array $k): bool => $k[0] === SupplierKeyKind::Name),
        ));
    }
}
