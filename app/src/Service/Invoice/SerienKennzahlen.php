<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\InvoiceDirection;
use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;
use App\Domain\SeriesStatus;

/**
 * The figures of the overview "Wiederkehrende Kosten" for one series
 * (issue #58/M8-2, docs/spec/03-erfassung-und-ki.md section 9, E-33): the
 * newest checked receipt and its amount, the yearly projection, the price
 * trend and whether the expected receipt is overdue.
 *
 * Pure - no database, no vault. The receipts come as plaintext dates; the
 * caller hands in how to decrypt one, and only the few receipts the
 * figures need are decrypted (the newest, the one the trend compares with).
 * Amounts stay integer cents; only the trend is a ratio, in tenths of a
 * percent.
 */
final readonly class SerienKennzahlen
{
    /**
     * How far back the trend looks: the newest receipt is compared with
     * the newest one at least this many days older (about a year), else
     * with the oldest receipt of the series.
     */
    public const int TREND_ABSTAND_TAGE = 335;

    public function __construct(
        /** The newest checked receipt of the series, null without any. */
        public ?SerienPosten $letzter,
        /** Expected amount times receipts per year, in cents; null without a fixed rhythm. */
        public ?int $jahreshochrechnung,
        /** Change from $trendBasis to $letzter in tenths of a percent (+52 = +5.2 %). */
        public ?int $trendPromille,
        /** The receipt the trend compares with. */
        public ?SerienPosten $trendBasis,
        public SerienFaelligkeit $faelligkeit,
    ) {
    }

    /**
     * @param array<int, \DateTimeImmutable> $belege invoice id => date of the
     *        series' checked receipts, any order
     * @param \Closure(int): ?SerienPosten $entschluessle a receipt, null when
     *        it cannot be read - it is skipped then
     */
    public static function berechne(RecurringSeriesRecord $record, SeriesData $daten, array $belege, \Closure $entschluessle, \DateTimeImmutable $heute): self
    {
        $ids = array_keys($belege);
        usort($ids, static fn(int $a, int $b): int => [$belege[$a], $a] <=> [$belege[$b], $b]);

        $gelesen = [];
        $lies = static function (int $id) use (&$gelesen, $entschluessle): ?SerienPosten {
            if (!array_key_exists($id, $gelesen)) {
                $gelesen[$id] = $entschluessle($id);
            }

            return $gelesen[$id];
        };

        $letzter = null;
        $rest = $ids;
        while ($letzter === null && $rest !== []) {
            $letzter = $lies(array_pop($rest));
        }

        $monate = $record->period->monate();
        $hochrechnung = $monate === null ? null : $daten->expectedGross * intdiv(12, $monate);

        [$trend, $basis] = $letzter === null ? [null, null] : self::trend($letzter, $rest, $belege, $lies, $daten->currency);

        return new self($letzter, $hochrechnung, $trend, $basis, self::faelligkeit($record, $heute));
    }

    /**
     * The yearly projection of confirmed series added up, per direction and
     * currency; series without a fixed rhythm do not count. Expenses first.
     *
     * @param list<SerienAnsicht> $serien
     *
     * @return list<array{richtung: InvoiceDirection, waehrung: string, cent: int, anzahl: int}>
     */
    public static function summen(array $serien): array
    {
        $summen = [];
        foreach ($serien as $serie) {
            $cent = $serie->kennzahlen?->jahreshochrechnung;
            if ($serie->record->status !== SeriesStatus::Bestaetigt || $cent === null) {
                continue;
            }
            $schluessel = $serie->record->direction->value . '|' . $serie->daten->currency;
            $summen[$schluessel] ??= ['richtung' => $serie->record->direction, 'waehrung' => $serie->daten->currency, 'cent' => 0, 'anzahl' => 0];
            $summen[$schluessel]['cent'] += $cent;
            ++$summen[$schluessel]['anzahl'];
        }
        ksort($summen);

        return array_values($summen);
    }

    /**
     * The trend of the newest receipt against an older one of the same
     * currency with an amount above zero; none when the newest itself is
     * not such a receipt (a credit note, another currency).
     *
     * @param list<int> $aelter the older receipts' ids, oldest first
     * @param array<int, \DateTimeImmutable> $belege
     * @param \Closure(int): ?SerienPosten $lies
     *
     * @return array{0: ?int, 1: ?SerienPosten}
     */
    private static function trend(SerienPosten $neuester, array $aelter, array $belege, \Closure $lies, string $waehrung): array
    {
        $passt = static fn(?SerienPosten $p): bool => $p !== null && $p->waehrung === $waehrung && $p->brutto > 0;
        if (!$passt($neuester)) {
            return [null, null];
        }
        $grenze = $neuester->datum->modify('-' . self::TREND_ABSTAND_TAGE . ' days');

        $basis = null;
        foreach (array_reverse($aelter) as $id) {
            if ($belege[$id] <= $grenze && $passt($p = $lies($id))) {
                $basis = $p;
                break;
            }
        }
        foreach ($basis === null ? $aelter : [] as $id) {
            if ($passt($p = $lies($id))) {
                $basis = $p;
                break;
            }
        }
        if ($basis === null) {
            return [null, null];
        }

        // Rounded half away from zero, in integers.
        $zaehler = ($neuester->brutto - $basis->brutto) * 1000;
        $promille = intdiv(2 * abs($zaehler) + $basis->brutto, 2 * $basis->brutto);

        return [$zaehler < 0 ? -$promille : $promille, $basis];
    }

    private static function faelligkeit(RecurringSeriesRecord $record, \DateTimeImmutable $heute): SerienFaelligkeit
    {
        if ($record->status !== SeriesStatus::Bestaetigt || $record->nextExpected === null) {
            return SerienFaelligkeit::Keine;
        }
        $tag = $heute->format('Y-m-d');
        $faellig = $record->nextExpected->format('Y-m-d');
        $ende = $record->nextExpected->modify('+' . $record->toleranceDays . ' days')->format('Y-m-d');

        return match (true) {
            $tag > $ende => SerienFaelligkeit::Ueberfaellig,
            $tag >= $faellig => SerienFaelligkeit::Faellig,
            default => SerienFaelligkeit::Offen,
        };
    }

    /** The trend for the page: "+5,2 %", "−3,0 %", "±0,0 %". */
    public function trendText(): ?string
    {
        if ($this->trendPromille === null) {
            return null;
        }
        $vorzeichen = match (true) {
            $this->trendPromille > 0 => '+',
            $this->trendPromille < 0 => '−',
            default => '±',
        };
        $betrag = abs($this->trendPromille);

        return sprintf('%s%d,%d %%', $vorzeichen, intdiv($betrag, 10), $betrag % 10);
    }
}
