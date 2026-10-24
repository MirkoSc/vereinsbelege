<?php

declare(strict_types=1);

namespace App\Tests\Service\Invoice;

use App\Domain\AssignmentRule;
use App\Domain\BankTransactionDirection;
use App\Domain\Belegmerkmale;
use App\Domain\InvoiceDirection;
use App\Domain\KategorieHerkunft;
use App\Service\Bank\Regelabgleich;
use App\Service\Invoice\KategorieVorschlag;
use App\Service\Invoice\Kategorisierung;
use PHPUnit\Framework\TestCase;

/**
 * The category suggested for a receipt (M7-7, issue #49, docs/spec/
 * 03-erfassung-und-ki.md section 8): rule > supplier default > learnt from
 * checked receipts > AI, and how a rule of the bookings matches a receipt.
 * Plain values, no database.
 */
final class KategorisierungTest extends TestCase
{
    private const string IBAN = 'DE89370400440532013000';

    private const int REGEL = 11;

    private const int STANDARD = 12;

    private const int GELERNT = 13;

    private const int KI = 14;

    // ------------------------------------------------ rules on a receipt

    public function testTheWordIsLookedForInThePurposeOfTheReceipt(): void
    {
        $regel = self::regel(stichwort: 'trainings  bälle');

        self::assertTrue(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Zehn TRAININGS bälle für die Jugend')));
        self::assertFalse(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Trikots')));
        self::assertFalse(Regelabgleich::trifftBeleg($regel, self::beleg(namen: ['Trainingsbälle GmbH'])), 'the word does not look at the partner');
    }

    public function testTheCounterpartyIsTheSupplierByNameAliasOrIban(): void
    {
        $name = self::regel(gegenseite: 'telekom');
        self::assertTrue(Regelabgleich::trifftBeleg($name, self::beleg(namen: ['Deutsche Telekom AG'])));
        self::assertTrue(Regelabgleich::trifftBeleg($name, self::beleg(namen: ['DT AG', 'Telekom Deutschland'])), 'an alias counts');
        self::assertFalse(Regelabgleich::trifftBeleg($name, self::beleg(namen: ['Vodafone'], zweck: 'statt Telekom')));
        self::assertFalse(Regelabgleich::trifftBeleg($name, self::beleg()), 'no partner, no match');

        $iban = self::regel(gegenseite: self::IBAN);
        self::assertTrue(Regelabgleich::trifftBeleg($iban, self::beleg(ibans: ['DE02120300000000202051', 'de89 3704 0044 0532 0130 00'])));
        self::assertFalse(Regelabgleich::trifftBeleg($iban, self::beleg(ibans: ['DE02120300000000202051'])));
        self::assertFalse(Regelabgleich::trifftBeleg($iban, self::beleg(namen: [self::IBAN])), 'an IBAN is never matched against the name');
    }

    public function testWordAndCounterpartyBothMatchAndTheDirectionNarrows(): void
    {
        $regel = self::regel(stichwort: 'Bälle', gegenseite: 'Sporthaus', direction: BankTransactionDirection::Ausgabe);

        self::assertTrue(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Bälle', namen: ['Sporthaus Beispiel'])));
        self::assertFalse(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Bälle', namen: ['Kaufhaus'])));
        self::assertFalse(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Trikots', namen: ['Sporthaus Beispiel'])));
        self::assertFalse(Regelabgleich::trifftBeleg($regel, self::beleg(zweck: 'Bälle', namen: ['Sporthaus Beispiel'], richtung: InvoiceDirection::Einnahme)));
        self::assertFalse(Regelabgleich::trifftBeleg(self::regel(), self::beleg(zweck: 'Bälle')), 'without a condition nothing matches');
    }

    // ------------------------------------------------------------- order

    public function testARuleWinsOverEverythingElse(): void
    {
        $vorschlag = self::vorschlagen([self::regel(stichwort: 'Bälle', categoryId: self::REGEL)]);

        self::assertSame(self::REGEL, $vorschlag?->kategorieId);
        self::assertSame(KategorieHerkunft::Regel, $vorschlag->herkunft);
        self::assertSame('Regel „Regel 1“', $vorschlag->beschreibung());
    }

    public function testTheSupplierDefaultComesBeforeWhatWasLearntAndTheAi(): void
    {
        $vorschlag = self::vorschlagen([]);

        self::assertSame(self::STANDARD, $vorschlag?->kategorieId);
        self::assertSame(KategorieHerkunft::Lieferant, $vorschlag->herkunft);
        self::assertSame('Standard-Kategorie des Lieferanten', $vorschlag->beschreibung());
        self::assertSame(
            'Standard-Kategorie des Zahlers',
            new KategorieVorschlag(self::STANDARD, KategorieHerkunft::Lieferant, InvoiceDirection::Einnahme)->beschreibung(),
        );
    }

    public function testTwoCheckedReceiptsWithTheSameCategoryComeBeforeTheAi(): void
    {
        $vorschlag = self::vorschlagen([], standard: null);

        self::assertSame(self::GELERNT, $vorschlag?->kategorieId);
        self::assertSame(KategorieHerkunft::Gelernt, $vorschlag->herkunft);
        self::assertSame('wie die letzten 2 geprüften Belege des Lieferanten', $vorschlag->beschreibung());
    }

    public function testTheAiComesLastWithItsConfidence(): void
    {
        $vorschlag = self::vorschlagen([], standard: null, letzte: []);

        self::assertSame(self::KI, $vorschlag?->kategorieId);
        self::assertSame(KategorieHerkunft::Ki, $vorschlag->herkunft);
        self::assertSame('KI 0,82', $vorschlag->beschreibung());
        self::assertSame('KI', self::vorschlagen([], standard: null, letzte: [], konfidenz: null)?->beschreibung());
        self::assertNull(self::vorschlagen([], standard: null, letzte: [], ki: null));
    }

    public function testOnlyTheNewestCheckedReceiptsCountAndTheyMustAgree(): void
    {
        self::assertSame(self::GELERNT, Kategorisierung::gelernt([self::GELERNT, self::GELERNT, 99]));
        self::assertNull(Kategorisierung::gelernt([self::GELERNT]), 'one receipt is not enough');
        self::assertNull(Kategorisierung::gelernt([self::GELERNT, 99, self::GELERNT]), 'the newest two differ');
        self::assertNull(Kategorisierung::gelernt([null, null]), 'no category is nothing to learn');
        self::assertNull(Kategorisierung::gelernt([self::GELERNT, null]));
    }

    public function testAnUnusableCategoryHandsOverToTheNextSource(): void
    {
        $nutzbar = static fn(int ...$ids): \Closure => static fn(int $id): bool => in_array($id, $ids, true);

        // A rule whose category is inactive or of the other direction does not count.
        self::assertSame(KategorieHerkunft::Lieferant, self::vorschlagen([self::regel(stichwort: 'Bälle', categoryId: self::REGEL)], passt: $nutzbar(self::STANDARD, self::GELERNT, self::KI))?->herkunft);
        self::assertSame(KategorieHerkunft::Gelernt, self::vorschlagen([], passt: $nutzbar(self::GELERNT, self::KI))?->herkunft);
        self::assertSame(KategorieHerkunft::Ki, self::vorschlagen([], passt: $nutzbar(self::KI))?->herkunft);
        self::assertNull(self::vorschlagen([], passt: $nutzbar()));
    }

    public function testTheOldestActiveMatchingRuleWithACategoryWins(): void
    {
        $regeln = [
            self::regel(id: 7, stichwort: 'Bälle', categoryId: 70),
            self::regel(id: 3, stichwort: 'Bälle', categoryId: 30, active: false),
            self::regel(id: 2, stichwort: 'Bälle', categoryId: null),
            self::regel(id: 5, stichwort: 'Trikots', categoryId: 50),
            self::regel(id: 6, gegenseite: 'Sporthaus', categoryId: 60),
        ];

        $vorschlag = self::vorschlagen($regeln);

        self::assertSame(60, $vorschlag?->kategorieId, 'inactive, without category and not matching are skipped');
        self::assertSame('Regel „Regel 6“', $vorschlag->beschreibung());
    }

    // ----------------------------------------------------------- helpers

    /**
     * Rule list, then the supplier's default, its newest checked receipts
     * and the AI - all present unless said otherwise.
     *
     * @param list<AssignmentRule> $regeln
     * @param list<?int> $letzte
     * @param ?\Closure(int): bool $passt
     */
    private static function vorschlagen(
        array $regeln,
        ?int $standard = self::STANDARD,
        array $letzte = [self::GELERNT, self::GELERNT],
        ?int $ki = self::KI,
        ?float $konfidenz = 0.82,
        ?\Closure $passt = null,
    ): ?KategorieVorschlag {
        return Kategorisierung::vorschlagen(
            $regeln,
            self::beleg(zweck: 'Zehn Bälle', namen: ['Sporthaus Beispiel GmbH']),
            $standard,
            $letzte,
            $ki,
            $konfidenz,
            $passt ?? static fn(int $id): bool => true,
        );
    }

    private static function regel(
        int $id = 1,
        string $stichwort = '',
        string $gegenseite = '',
        ?BankTransactionDirection $direction = null,
        ?int $categoryId = self::REGEL,
        bool $active = true,
    ): AssignmentRule {
        $jetzt = new \DateTimeImmutable('2026-10-24');

        return new AssignmentRule($id, 'Regel ' . $id, $stichwort, $gegenseite, $direction, false, $categoryId, $active, $jetzt, $jetzt);
    }

    /**
     * @param list<string> $namen
     * @param list<string> $ibans
     */
    private static function beleg(
        string $zweck = '',
        array $namen = [],
        array $ibans = [],
        InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
    ): Belegmerkmale {
        return new Belegmerkmale($richtung, $zweck, $namen, $ibans);
    }
}
