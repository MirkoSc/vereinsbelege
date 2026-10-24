<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The rhythm of a recurring series (`recurring_series.period`, issue
 * #57/M8-1, docs/spec/03-erfassung-und-ki.md section 9) - the values the AI
 * schema uses for `recurring.period`, plus `unregelmaessig` for a series a
 * person confirms without a fixed rhythm (no next date then). Detection
 * never proposes that one.
 */
enum SeriesPeriod: string
{
    case Monat = 'monat';
    case Quartal = 'quartal';
    case Halbjahr = 'halbjahr';
    case Jahr = 'jahr';
    case Unregelmaessig = 'unregelmaessig';

    public function label(): string
    {
        return match ($this) {
            self::Monat => 'monatlich',
            self::Quartal => 'quartalsweise',
            self::Halbjahr => 'halbjährlich',
            self::Jahr => 'jährlich',
            self::Unregelmaessig => 'unregelmäßig',
        };
    }

    /** Months from one receipt to the next; null without a fixed rhythm. */
    public function monate(): ?int
    {
        return match ($this) {
            self::Monat => 1,
            self::Quartal => 3,
            self::Halbjahr => 6,
            self::Jahr => 12,
            self::Unregelmaessig => null,
        };
    }

    /** The mean length in days (a month is 30.44 days). */
    public function tage(): ?float
    {
        $monate = $this->monate();

        return $monate === null ? null : $monate * 365.25 / 12;
    }

    /**
     * How far the median gap between the receipts may be off the mean
     * length for detection to call it this rhythm.
     */
    public function medianToleranzTage(): int
    {
        return match ($this) {
            self::Monat => 5,
            self::Quartal => 12,
            self::Halbjahr => 20,
            self::Jahr => 30,
            self::Unregelmaessig => 0,
        };
    }

    /**
     * The default `tolerance_days` of a new series: how late the expected
     * receipt may be before it counts as missing (M8-2).
     */
    public function standardToleranzTage(): int
    {
        return match ($this) {
            self::Monat => 7,
            self::Quartal => 14,
            self::Halbjahr => 21,
            self::Jahr => 30,
            self::Unregelmaessig => 0,
        };
    }

    /**
     * The rhythms detection looks for, shortest first.
     *
     * @return list<self>
     */
    public static function erkennbare(): array
    {
        return [self::Monat, self::Quartal, self::Halbjahr, self::Jahr];
    }
}
