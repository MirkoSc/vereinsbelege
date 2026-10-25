<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The period filter of the report pages (docs/spec/05-auswertung-und-export.md
 * section 1: calendar year = fiscal year (E-16), quarter, month, free; issue
 * #73, E-51): read from the query string, turned into a Zeitraum and back
 * into query parameters for links. Only plain numbers and `YYYY-MM-DD`
 * dates are taken - nothing else of the request reaches a query.
 *
 * An invalid or incomplete choice falls back to the default year and says
 * so ($korrigiert), it is never guessed. The reader's period scope is not
 * applied here: Zeitraum::begrenztAuf() narrows the result where it is used.
 */
final readonly class Zeitraumwahl
{
    /**
     * The longest free period, in years: one request decrypts every line of
     * it (CLAUDE.md section 1 - no long request; the session cache caps its
     * size too). Longer views are what the yearly figures are for.
     */
    public const int MAX_JAHRE_FREI = 3;

    public const array MONATSNAMEN = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    private function __construct(
        public Zeitraumart $art,
        public int $jahr,
        /** 1 … 4, only meaningful for Zeitraumart::Quartal. */
        public int $quartal,
        /** 1 … 12, only meaningful for Zeitraumart::Monat. */
        public int $monat,
        /** Only for Zeitraumart::Frei. */
        public ?\DateTimeImmutable $von,
        public ?\DateTimeImmutable $bis,
        /** The request asked for something invalid; the default year is shown instead. */
        public bool $korrigiert = false,
    ) {
    }

    public static function jahr(int $jahr): self
    {
        return new self(Zeitraumart::Jahr, $jahr, 1, 1, null, null);
    }

    /**
     * @param array<array-key, mixed> $query the request's query parameters:
     *        `zeitraum` (jahr|quartal|monat|frei), `jahr`, `quartal`,
     *        `monat`, `von`, `bis`
     * @param int $standardJahr shown without a (valid) choice
     * @param int $erstesJahr the first year that may be chosen
     * @param int $letztesJahr the last year that may be chosen
     */
    public static function ausAnfrage(array $query, int $standardJahr, int $erstesJahr, int $letztesJahr): self
    {
        $standard = self::jahr($standardJahr);
        $artWert = $query['zeitraum'] ?? null;
        if ($artWert === null || $artWert === '') {
            // Only a year given (a link like the dashboard's ?jahr=).
            if (!isset($query['jahr'])) {
                return $standard;
            }
            $artWert = Zeitraumart::Jahr->value;
        }
        $art = is_string($artWert) ? Zeitraumart::tryFrom($artWert) : null;
        if ($art === null) {
            return $standard->alsKorrigiert();
        }

        if ($art === Zeitraumart::Frei) {
            $von = self::datum($query['von'] ?? null);
            $bis = self::datum($query['bis'] ?? null);
            if ($von === null || $bis === null || $bis < $von
                || (int) $von->format('Y') < $erstesJahr || (int) $bis->format('Y') > $letztesJahr
                || $bis >= $von->modify('+' . self::MAX_JAHRE_FREI . ' years')) {
                return $standard->alsKorrigiert();
            }

            return new self(Zeitraumart::Frei, (int) $von->format('Y'), 1, 1, $von, $bis);
        }

        $jahr = self::zahl($query['jahr'] ?? null, $erstesJahr, $letztesJahr);
        $quartal = $art === Zeitraumart::Quartal ? self::zahl($query['quartal'] ?? null, 1, 4) : 1;
        $monat = $art === Zeitraumart::Monat ? self::zahl($query['monat'] ?? null, 1, 12) : 1;
        if ($jahr === null || $quartal === null || $monat === null) {
            return $standard->alsKorrigiert();
        }

        return new self($art, $jahr, $quartal, $monat, null, null);
    }

    public function zeitraum(): Zeitraum
    {
        return match ($this->art) {
            Zeitraumart::Jahr => Zeitraum::jahr($this->jahr),
            Zeitraumart::Quartal => Zeitraum::quartal($this->jahr, $this->quartal),
            Zeitraumart::Monat => Zeitraum::monat($this->jahr, $this->monat),
            Zeitraumart::Frei => Zeitraum::frei($this->von ?? throw new \LogicException('free period without start'), $this->bis ?? throw new \LogicException('free period without end')),
        };
    }

    /**
     * The choice as query parameters - only the ones its kind needs, so a
     * link carries nothing but structure (no amounts, no names).
     *
     * @return array<string, string>
     */
    public function parameter(): array
    {
        return match ($this->art) {
            Zeitraumart::Jahr => ['zeitraum' => 'jahr', 'jahr' => (string) $this->jahr],
            Zeitraumart::Quartal => ['zeitraum' => 'quartal', 'jahr' => (string) $this->jahr, 'quartal' => (string) $this->quartal],
            Zeitraumart::Monat => ['zeitraum' => 'monat', 'jahr' => (string) $this->jahr, 'monat' => (string) $this->monat],
            Zeitraumart::Frei => ['zeitraum' => 'frei', 'von' => $this->zeitraum()->von->format('Y-m-d'), 'bis' => $this->zeitraum()->bis->format('Y-m-d')],
        };
    }

    /** "2025", "2. Quartal 2025", "März 2025", "01.03.2025 – 15.04.2025". */
    public function bezeichnung(): string
    {
        return match ($this->art) {
            Zeitraumart::Jahr => (string) $this->jahr,
            Zeitraumart::Quartal => sprintf('%d. Quartal %d', $this->quartal, $this->jahr),
            Zeitraumart::Monat => self::MONATSNAMEN[$this->monat] . ' ' . $this->jahr,
            Zeitraumart::Frei => $this->zeitraum()->von->format('d.m.Y') . ' – ' . $this->zeitraum()->bis->format('d.m.Y'),
        };
    }

    private function alsKorrigiert(): self
    {
        return new self($this->art, $this->jahr, $this->quartal, $this->monat, $this->von, $this->bis, true);
    }

    private static function zahl(mixed $wert, int $min, int $max): ?int
    {
        if (!is_string($wert) || preg_match('/^\d{1,4}$/', $wert) !== 1) {
            return null;
        }
        $zahl = (int) $wert;

        return $zahl >= $min && $zahl <= $max ? $zahl : null;
    }

    /** A calendar date `YYYY-MM-DD` (as an HTML date field sends it), else null. */
    private static function datum(mixed $wert): ?\DateTimeImmutable
    {
        if (!is_string($wert) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert) !== 1) {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
    }
}
