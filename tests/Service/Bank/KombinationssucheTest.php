<?php

declare(strict_types=1);

namespace App\Tests\Service\Bank;

use App\Service\Bank\Kombinationssuche;
use PHPUnit\Framework\TestCase;

/**
 * The bounded subset sum behind collective reimbursements (M10-3, issue
 * #67, docs/spec/04-bank-und-abgleich.md section 5): plain values.
 */
final class KombinationssucheTest extends TestCase
{
    public function testASingleReceiptMatchingExactlyComesFirst(): void
    {
        $ergebnis = new Kombinationssuche()->suchen(5000, [1 => 2000, 2 => 3000, 3 => 5000]);

        self::assertSame([[3], [1, 2]], $ergebnis->treffer);
        self::assertTrue($ergebnis->vollstaendig);
    }

    public function testSeveralReceiptsAddUpToOneTransfer(): void
    {
        $ergebnis = new Kombinationssuche()->suchen(12345, [10 => 1000, 11 => 4321, 12 => 999, 13 => 7024, 14 => 50]);

        self::assertSame([[10, 11, 13]], $ergebnis->treffer);
        self::assertTrue($ergebnis->vollstaendig);
    }

    public function testNothingAddsUp(): void
    {
        $ergebnis = new Kombinationssuche()->suchen(1000, [1 => 300, 2 => 300, 3 => 300]);

        self::assertSame([], $ergebnis->treffer);
        self::assertTrue($ergebnis->vollstaendig);
    }

    public function testAtMostSixReceiptsPerCombination(): void
    {
        $sieben = array_fill(1, 7, 100);

        self::assertSame([], new Kombinationssuche()->suchen(700, $sieben)->treffer);
        self::assertSame([[1, 2, 3, 4, 5, 6]], new Kombinationssuche()->suchen(600, array_fill(1, 6, 100))->treffer);
    }

    public function testOnlyTheOldestCandidatesAreSearchedAndTheResultSaysSo(): void
    {
        $offen = [];
        for ($i = 1; $i <= Kombinationssuche::MAX_KANDIDATEN + 1; ++$i) {
            $offen[$i] = 100 * $i;
        }

        // The only receipt of exactly 2 100 is the 21st - beyond the bound.
        $ergebnis = new Kombinationssuche(maxBelege: 1)->suchen(100 * (Kombinationssuche::MAX_KANDIDATEN + 1), $offen);

        self::assertSame([], $ergebnis->treffer);
        self::assertFalse($ergebnis->vollstaendig);
    }

    public function testTheStepBudgetEndsTheSearch(): void
    {
        // 20 equal amounts, a target nothing reaches: without a budget all
        // 60 459 index sets of size 1..6 would be visited.
        $offen = array_fill(1, 20, 7);
        $start = hrtime(true);
        $ergebnis = new Kombinationssuche(maxSchritte: 1000)->suchen(1_000_000, $offen);

        self::assertSame([], $ergebnis->treffer);
        self::assertFalse($ergebnis->vollstaendig);
        self::assertLessThan(1_000_000_000, hrtime(true) - $start, 'well below a second');

        $voll = new Kombinationssuche()->suchen(1_000_000, $offen);
        self::assertFalse($voll->vollstaendig, 'the default budget is below the full search space');
    }

    public function testTheNumberOfHitsIsBoundedAndTheResultSaysSo(): void
    {
        $ergebnis = new Kombinationssuche()->suchen(200, array_fill(1, 10, 100));

        self::assertCount(Kombinationssuche::MAX_TREFFER, $ergebnis->treffer);
        self::assertSame([1, 2], $ergebnis->treffer[0]);
        self::assertFalse($ergebnis->vollstaendig);
    }

    public function testAmountsAboveTheTargetAndNonPositiveOnesAreIgnored(): void
    {
        $ergebnis = new Kombinationssuche()->suchen(500, [1 => 0, 2 => -500, 3 => 900, 4 => 500]);

        self::assertSame([[4]], $ergebnis->treffer);
        self::assertSame([], new Kombinationssuche()->suchen(0, [1 => 0])->treffer);
    }
}
