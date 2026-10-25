<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Zugriffsbereich;

/**
 * The period of a report: whole days from $von to $bis, both included
 * (docs/spec/05-auswertung-und-export.md section 1: calendar year = fiscal
 * year (E-16), quarter, month, free). Dates are compared as `Y-m-d`, so the
 * time of day of a value never matters.
 */
final readonly class Zeitraum
{
    public \DateTimeImmutable $von;

    public \DateTimeImmutable $bis;

    private function __construct(\DateTimeImmutable $von, \DateTimeImmutable $bis)
    {
        $this->von = self::tag($von);
        $this->bis = self::tag($bis);
        if ($this->bis < $this->von) {
            throw new \InvalidArgumentException('Das Ende des Zeitraums liegt vor seinem Anfang.');
        }
    }

    public static function jahr(int $jahr): self
    {
        return new self(self::datum($jahr, 1, 1), self::datum($jahr, 12, 31));
    }

    public static function quartal(int $jahr, int $quartal): self
    {
        if ($quartal < 1 || $quartal > 4) {
            throw new \InvalidArgumentException('Ein Quartal liegt zwischen 1 und 4.');
        }
        $erster = self::datum($jahr, 3 * $quartal - 2, 1);

        return new self($erster, $erster->modify('+2 months')->modify('last day of this month'));
    }

    public static function monat(int $jahr, int $monat): self
    {
        if ($monat < 1 || $monat > 12) {
            throw new \InvalidArgumentException('Ein Monat liegt zwischen 1 und 12.');
        }
        $erster = self::datum($jahr, $monat, 1);

        return new self($erster, $erster->modify('last day of this month'));
    }

    public static function frei(\DateTimeImmutable $von, \DateTimeImmutable $bis): self
    {
        return new self($von, $bis);
    }

    /**
     * The same period a year earlier. A month end stays a month end (the
     * February of a leap year ends on the 29th), a 29 February that starts
     * a period becomes the 28th.
     */
    public function vorjahr(): self
    {
        return new self(self::jahrZurueck($this->von, false), self::jahrZurueck($this->bis, true));
    }

    public function enthaelt(\DateTimeImmutable $datum): bool
    {
        $tag = $datum->format('Y-m-d');

        return $tag >= $this->von->format('Y-m-d') && $tag <= $this->bis->format('Y-m-d');
    }

    /**
     * The part of this period the reader's period scope allows (external
     * accounts see only their released period); null if nothing is left.
     */
    public function begrenztAuf(Zugriffsbereich $bereich): ?self
    {
        $von = $bereich->von !== null && self::tag($bereich->von) > $this->von ? $bereich->von : $this->von;
        $bis = $bereich->bis !== null && self::tag($bereich->bis) < $this->bis ? $bereich->bis : $this->bis;

        return self::tag($bis) < self::tag($von) ? null : new self($von, $bis);
    }

    /** @return list<string> the months touched, as `YYYY-MM`, in order */
    public function monate(): array
    {
        $monate = [];
        $ende = $this->bis->format('Y-m');
        for ($m = $this->von->modify('first day of this month'); $m->format('Y-m') <= $ende; $m = $m->modify('+1 month')) {
            $monate[] = $m->format('Y-m');
        }

        return $monate;
    }

    private static function tag(\DateTimeImmutable $wert): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wert->format('Y-m-d'));
    }

    private static function datum(int $jahr, int $monat, int $tag): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
    }

    private static function jahrZurueck(\DateTimeImmutable $tag, bool $monatsendeBleibt): \DateTimeImmutable
    {
        $erster = self::datum((int) $tag->format('Y') - 1, (int) $tag->format('n'), 1);
        $letzter = (int) $erster->format('t');
        $istMonatsende = (int) $tag->format('j') === (int) $tag->format('t');
        $neu = $monatsendeBleibt && $istMonatsende ? $letzter : min((int) $tag->format('j'), $letzter);

        return $erster->setDate((int) $erster->format('Y'), (int) $erster->format('n'), $neu);
    }
}
