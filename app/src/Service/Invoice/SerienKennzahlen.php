<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesData;
use App\Domain\SeriesStatus;

/**
 * The figures of the overview "Wiederkehrende Kosten" for one series
 * (issue #58/M8-2, docs/spec/03-erfassung-und-ki.md section 9, E-33): the
 * newest receipt and its amount, the yearly projection, the price trend and
 * whether the expected receipt is overdue.
 *
 * Pure - no database, no vault: it works on what Serien decrypted. Amounts
 * stay integer cents; only the trend is a ratio, in tenths of a percent.
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
        /** The newest receipt of the series, null without any. */
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
     * @param list<SerienPosten> $mitglieder the receipts of the series, any order
     */
    public static function berechne(RecurringSeriesRecord $record, SeriesData $daten, array $mitglieder, \DateTimeImmutable $heute): self
    {
        usort($mitglieder, static fn(SerienPosten $a, SerienPosten $b): int => [$a->datum, $a->invoiceId] <=> [$b->datum, $b->invoiceId]);
        $letzter = $mitglieder === [] ? null : $mitglieder[array_key_last($mitglieder)];

        $monate = $record->period->monate();
        $hochrechnung = $monate === null ? null : $daten->expectedGross * intdiv(12, $monate);

        [$trend, $basis] = self::trend($mitglieder, $daten->currency);

        return new self($letzter, $hochrechnung, $trend, $basis, self::faelligkeit($record, $heute));
    }

    /**
     * @param list<SerienPosten> $sortiert oldest first
     *
     * @return array{0: ?int, 1: ?SerienPosten}
     */
    private static function trend(array $sortiert, string $waehrung): array
    {
        $passend = array_values(array_filter(
            $sortiert,
            static fn(SerienPosten $p): bool => $p->waehrung === $waehrung && $p->brutto > 0,
        ));
        if (count($passend) < 2) {
            return [null, null];
        }
        $neuester = $passend[count($passend) - 1];
        $grenze = $neuester->datum->modify('-' . self::TREND_ABSTAND_TAGE . ' days');
        $basis = $passend[0];
        foreach ($passend as $posten) {
            if ($posten->datum <= $grenze) {
                $basis = $posten;
            }
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
