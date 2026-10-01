<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\CashCount;
use App\Domain\CashCountOutcome;
use App\Service\Bank\KassensturzVorschau;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The difference of a cash count (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1 "Kassensturz"): counted minus
 * expected, in integer cents - negative is a shortfall, positive a surplus.
 */
final class CashCountTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, CashCountOutcome}>
     */
    public static function faelle(): iterable
    {
        yield 'shortfall' => [15000, 14250, -750, CashCountOutcome::Fehlbetrag];
        yield 'match' => [15000, 15000, 0, CashCountOutcome::Stimmt];
        yield 'surplus' => [15000, 15120, 120, CashCountOutcome::Ueberschuss];
        yield 'one cent short of an empty box' => [1, 0, -1, CashCountOutcome::Fehlbetrag];
        yield 'counted although nothing was expected' => [0, 5, 5, CashCountOutcome::Ueberschuss];
    }

    #[DataProvider('faelle')]
    public function testTheDifferenceIsCountedMinusExpected(int $soll, int $ist, int $differenz, CashCountOutcome $ergebnis): void
    {
        $zaehlung = new CashCount(1, 2, new \DateTimeImmutable('2026-06-30'), $soll, $ist, 'EUR', '', null, new \DateTimeImmutable());
        $vorschau = new KassensturzVorschau(new \DateTimeImmutable('2026-06-30'), $soll, $ist, '');

        self::assertSame($differenz, $zaehlung->differenz());
        self::assertSame($ergebnis, $zaehlung->ergebnis());
        self::assertSame($differenz, $vorschau->differenz(), 'the preview computes the same as the stored count');
        self::assertSame($ergebnis, $vorschau->ergebnis());
    }

    public function testEveryOutcomeHasALabelAndADesignsystemMarker(): void
    {
        foreach (CashCountOutcome::cases() as $ergebnis) {
            self::assertNotSame('', $ergebnis->label());
            self::assertContains($ergebnis->marke(), ['marke-ok', 'marke-warnung', 'marke-fehler']);
        }
        self::assertSame('Fehlbetrag', CashCountOutcome::Fehlbetrag->label());
    }
}
