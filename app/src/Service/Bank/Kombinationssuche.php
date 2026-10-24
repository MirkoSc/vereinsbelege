<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * Which open reimbursements add up to one transfer (M10-3, issue #67,
 * docs/spec/04-bank-und-abgleich.md section 5, E-36): a bounded subset sum
 * over integer cents. Pure - no database, no vault -, so it is tested on
 * its own and stays fast enough for one short request.
 *
 * Bounds, all three deliberate (CLAUDE.md section 1, no long request):
 * - at most MAX_BELEGE receipts per combination (spec: 6),
 * - at most MAX_KANDIDATEN candidates - the oldest, in the order given,
 * - at most MAX_SCHRITTE visited nodes, a deterministic stand-in for a
 *   time limit (20 000 nodes take a few milliseconds).
 * Smaller combinations come first (iterative deepening), within one size
 * the order of the candidates (oldest first). The result says when a bound
 * cut the search short - the person then matches by hand.
 */
final readonly class Kombinationssuche
{
    public const int MAX_BELEGE = 6;

    public const int MAX_KANDIDATEN = 20;

    public const int MAX_SCHRITTE = 20_000;

    public const int MAX_TREFFER = 5;

    public function __construct(
        private int $maxBelege = self::MAX_BELEGE,
        private int $maxKandidaten = self::MAX_KANDIDATEN,
        private int $maxSchritte = self::MAX_SCHRITTE,
        private int $maxTreffer = self::MAX_TREFFER,
    ) {
    }

    /**
     * @param int $ziel the amount to reach, cents > 0
     * @param array<int, int> $offen candidate id => open amount in cents, in order of preference
     */
    public function suchen(int $ziel, array $offen): Kombinationsergebnis
    {
        if ($ziel <= 0) {
            return new Kombinationsergebnis([], true);
        }

        $ids = [];
        $betraege = [];
        foreach ($offen as $id => $betrag) {
            if ($betrag > 0 && $betrag <= $ziel) {
                $ids[] = $id;
                $betraege[] = $betrag;
            }
        }
        $vollstaendig = true;
        if (count($ids) > $this->maxKandidaten) {
            $ids = array_slice($ids, 0, $this->maxKandidaten);
            $betraege = array_slice($betraege, 0, $this->maxKandidaten);
            $vollstaendig = false;
        }

        $treffer = [];
        $schritte = 0;
        $abbruch = false;
        $groesste = min($this->maxBelege, count($ids));
        for ($k = 1; $k <= $groesste && !$abbruch; ++$k) {
            $this->tiefe($betraege, $ziel, $k, 0, 0, [], $treffer, $schritte, $abbruch);
        }
        if ($abbruch) {
            $vollstaendig = false;
        }

        return new Kombinationsergebnis(
            array_map(static fn(array $indizes): array => array_map(static fn(int $i): int => $ids[$i], $indizes), $treffer),
            $vollstaendig,
        );
    }

    /**
     * Depth-first over index sets of size $k in ascending index order.
     *
     * @param list<int> $betraege
     * @param list<int> $gewaehlt indices chosen so far
     * @param list<list<int>> $treffer
     */
    private function tiefe(array $betraege, int $ziel, int $k, int $start, int $summe, array $gewaehlt, array &$treffer, int &$schritte, bool &$abbruch): void
    {
        $fehlen = $k - count($gewaehlt);
        $anzahl = count($betraege);
        for ($i = $start; $i <= $anzahl - $fehlen; ++$i) {
            if (++$schritte > $this->maxSchritte) {
                $abbruch = true;

                return;
            }
            $neu = $summe + $betraege[$i];
            if ($neu > $ziel) {
                continue;
            }
            if ($fehlen === 1) {
                if ($neu === $ziel) {
                    $treffer[] = [...$gewaehlt, $i];
                    if (count($treffer) >= $this->maxTreffer) {
                        // More may exist: the person sees that the list is cut.
                        $abbruch = true;

                        return;
                    }
                }
                continue;
            }
            $this->tiefe($betraege, $ziel, $k, $i + 1, $neu, [...$gewaehlt, $i], $treffer, $schritte, $abbruch);
            if ($abbruch) {
                return;
            }
        }
    }
}
