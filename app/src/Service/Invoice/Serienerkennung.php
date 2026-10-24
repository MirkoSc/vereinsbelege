<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\SeriesPeriod;

/**
 * Series detection (issue #57/M8-1, docs/spec/03-erfassung-und-ki.md
 * section 9, E-26) - pure, without database or vault, so it is tested on
 * its own. App\Service\Invoice\Serien feeds it the decrypted checked
 * receipts of one supplier and direction that belong to no series yet.
 *
 * A series is
 * - at least 3 receipts (2 when the AI read `recurring.likely` on the
 *   newest one - and then of the rhythm the AI named, if it named one),
 * - in the same currency, each amount within the tolerance (default ±15 %,
 *   setting `serien_toleranz_prozent`) of the next newer one - a price
 *   increase step by step stays in the series, a jump ends it,
 * - whose median gap fits a month, quarter, half year or year
 *   (SeriesPeriod::medianToleranzTage()), with no gap under half the rhythm
 *   (two receipts in one month are not a monthly series). A gap of more
 *   than 2.5 rhythms (more than one receipt missing) cuts the series: only
 *   the part after it counts.
 *
 * The chain starts at the newest receipt; if that one does not start a
 * series (a one-off purchase from the landlord), the next older one is
 * tried. Receipts that do not fit the amount are skipped, not fatal.
 */
final readonly class Serienerkennung
{
    public const int TOLERANZ_PROZENT_STANDARD = 15;

    public const string SETTING_TOLERANZ = 'serien_toleranz_prozent';

    /** Receipts looked at, newest first - more is history, not detection. */
    public const int BELEGE_MAX = 60;

    /** A gap of more than this many rhythms cuts the series. */
    private const float LUECKE_MAX = 2.5;

    public function __construct(private int $toleranzProzent = self::TOLERANZ_PROZENT_STANDARD)
    {
        if ($toleranzProzent < 0 || $toleranzProzent > 100) {
            throw new \InvalidArgumentException('Die Betragstoleranz liegt zwischen 0 und 100 %.');
        }
    }

    /**
     * @param list<SerienBeleg> $belege in any order
     * @param bool $kiHinweis the AI read `recurring.likely` on the newest receipt
     * @param ?SeriesPeriod $kiPeriode the rhythm the AI named with it
     */
    public function erkenne(array $belege, bool $kiHinweis = false, ?SeriesPeriod $kiPeriode = null): ?SerienKandidat
    {
        $belege = array_values(array_filter($belege, static fn(SerienBeleg $b): bool => $b->brutto > 0));
        usort($belege, static fn(SerienBeleg $a, SerienBeleg $b): int => [$b->datum, $b->invoiceId] <=> [$a->datum, $a->invoiceId]);
        $belege = array_slice($belege, 0, self::BELEGE_MAX);

        foreach ($belege as $i => $anker) {
            $kette = [$anker];
            foreach (array_slice($belege, $i + 1) as $aelter) {
                $neuester = $kette[count($kette) - 1];
                if ($aelter->waehrung === $anker->waehrung && $this->betragPasst($aelter->brutto, $neuester->brutto)) {
                    $kette[] = $aelter;
                }
            }
            // Only the newest receipt carries the AI's hint.
            $kandidat = self::bewerte(array_reverse($kette), $i === 0 && $kiHinweis, $kiPeriode);
            if ($kandidat !== null) {
                return $kandidat;
            }
        }

        return null;
    }

    /** Whether $betrag is within the tolerance of $bezug. */
    public function betragPasst(int $betrag, int $bezug): bool
    {
        return abs($betrag - $bezug) * 100 <= $this->toleranzProzent * abs($bezug);
    }

    /**
     * The date after $letztes one rhythm later, the day clamped to the
     * month's end (31 January + 1 month = 28/29 February). Null without a
     * fixed rhythm.
     */
    public static function naechsteFaelligkeit(\DateTimeImmutable $letztes, SeriesPeriod $periode): ?\DateTimeImmutable
    {
        $monate = $periode->monate();
        if ($monate === null) {
            return null;
        }
        $erster = $letztes->setDate((int) $letztes->format('Y'), (int) $letztes->format('n'), 1)->modify(sprintf('+%d months', $monate));
        $tag = min((int) $letztes->format('j'), (int) $erster->format('t'));

        return $erster->setDate((int) $erster->format('Y'), (int) $erster->format('n'), $tag);
    }

    /**
     * @param list<SerienBeleg> $kette oldest first, amounts already fitting
     */
    private static function bewerte(array $kette, bool $kiHinweis, ?SeriesPeriod $kiPeriode): ?SerienKandidat
    {
        $mindestens = $kiHinweis ? 2 : 3;
        if (count($kette) < $mindestens) {
            return null;
        }

        foreach (SeriesPeriod::erkennbare() as $periode) {
            if ($kiHinweis && count($kette) < 3 && $kiPeriode !== null && $kiPeriode !== $periode) {
                continue;
            }
            $teil = self::nachLetzterLuecke($kette, $periode);
            if (count($teil) < $mindestens) {
                continue;
            }
            $abstaende = self::abstaende($teil);
            $laenge = (float) $periode->tage();
            if (min($abstaende) < $laenge / 2) {
                continue;
            }
            if (abs(self::median($abstaende) - $laenge) > $periode->medianToleranzTage()) {
                continue;
            }
            $letzter = $teil[count($teil) - 1];
            $naechste = self::naechsteFaelligkeit($letzter->datum, $periode);
            assert($naechste !== null);

            return new SerienKandidat(
                $periode,
                array_map(static fn(SerienBeleg $b): int => $b->invoiceId, $teil),
                $letzter->brutto,
                $letzter->waehrung,
                $letzter->datum,
                $naechste,
            );
        }

        return null;
    }

    /**
     * The receipts after the last gap longer than LUECKE_MAX rhythms.
     *
     * @param list<SerienBeleg> $kette oldest first
     *
     * @return list<SerienBeleg>
     */
    private static function nachLetzterLuecke(array $kette, SeriesPeriod $periode): array
    {
        $grenze = (float) $periode->tage() * self::LUECKE_MAX;
        $start = 0;
        foreach (self::abstaende($kette) as $i => $abstand) {
            if ($abstand > $grenze) {
                $start = $i + 1;
            }
        }

        return array_slice($kette, $start);
    }

    /**
     * Days between neighbouring receipts.
     *
     * @param list<SerienBeleg> $kette oldest first, at least two
     *
     * @return non-empty-list<int>
     */
    private static function abstaende(array $kette): array
    {
        $abstaende = [];
        for ($i = 1, $n = count($kette); $i < $n; $i++) {
            $abstaende[] = (int) $kette[$i - 1]->datum->diff($kette[$i]->datum)->days;
        }
        assert($abstaende !== []);

        return $abstaende;
    }

    /**
     * @param non-empty-list<int> $werte
     */
    private static function median(array $werte): float
    {
        sort($werte);
        $n = count($werte);
        $mitte = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $werte[$mitte] : ($werte[$mitte - 1] + $werte[$mitte]) / 2;
    }
}
