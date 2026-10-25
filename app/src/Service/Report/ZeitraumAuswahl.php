<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The period a report page shows, read from its query string
 * (docs/spec/05-auswertung-und-export.md section 1: "Kalenderjahr
 * (= Geschäftsjahr)/Quartal/Monat/frei", E-16). First used by the
 * Kassenprüfung (issue #74); meant for every page under /app/auswertung.
 *
 *     ?art=jahr&jahr=2025
 *     ?art=quartal&jahr=2025&quartal=2
 *     ?art=monat&jahr=2025&monat=7
 *     ?art=frei&von=2025-01-01&bis=2025-06-30
 *
 * Plain numbers and dates only; anything malformed or out of range falls
 * back to the page's default - it is a filter, not a form. Years run from
 * ERSTES_JAHR to the year after today. Nothing of the request reaches a
 * query except the dates of the resulting Zeitraum.
 *
 * A free period spans at most MAX_JAHRE_FREI years (E-51, issue #250): one
 * request decrypts every line of it (CLAUDE.md section 1). A longer one
 * falls back to the default too, but marked `zuLang`, so the period filter
 * (partials/zeitraum-filter.php) says why.
 */
final readonly class ZeitraumAuswahl
{
    public const int ERSTES_JAHR = 2000;

    /** The longest free period a request may ask for, in years. */
    public const int MAX_JAHRE_FREI = 3;

    public const array ARTEN = ['jahr' => 'Jahr', 'quartal' => 'Quartal', 'monat' => 'Monat', 'frei' => 'Frei'];

    private function __construct(
        /** One of the keys of ARTEN. */
        public string $art,
        public Zeitraum $zeitraum,
        /** The requested free period was too long; this is the default instead. */
        public bool $zuLang = false,
    ) {
    }

    public static function jahr(int $jahr): self
    {
        return new self('jahr', Zeitraum::jahr($jahr));
    }

    /**
     * A free period - shown as a calendar year when it is exactly one.
     */
    public static function frei(\DateTimeImmutable $von, \DateTimeImmutable $bis): self
    {
        $zeitraum = Zeitraum::frei($von, $bis);
        $jahr = (int) $zeitraum->von->format('Y');
        if ($zeitraum->von->format('m-d') === '01-01' && $zeitraum->bis->format('Y-m-d') === sprintf('%04d-12-31', $jahr)) {
            return self::jahr($jahr);
        }

        return new self('frei', $zeitraum);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function ausAnfrage(array $query, self $standard, \DateTimeImmutable $heute): self
    {
        $letztesJahr = (int) $heute->format('Y') + 1;
        $zahl = static function (mixed $wert, int $min, int $max): ?int {
            if (!is_string($wert) || preg_match('/^\d{1,4}$/', $wert) !== 1) {
                return null;
            }
            $n = (int) $wert;

            return $n >= $min && $n <= $max ? $n : null;
        };
        $jahr = $zahl($query['jahr'] ?? null, self::ERSTES_JAHR, $letztesJahr);

        return match ($query['art'] ?? null) {
            'jahr' => $jahr === null ? $standard : self::jahr($jahr),
            'quartal' => ($q = $zahl($query['quartal'] ?? null, 1, 4)) === null || $jahr === null
                ? $standard
                : new self('quartal', Zeitraum::quartal($jahr, $q)),
            'monat' => ($m = $zahl($query['monat'] ?? null, 1, 12)) === null || $jahr === null
                ? $standard
                : new self('monat', Zeitraum::monat($jahr, $m)),
            'frei' => self::freiAusAnfrage($query['von'] ?? null, $query['bis'] ?? null, $letztesJahr, $standard) ?? $standard,
            default => $standard,
        };
    }

    /**
     * The query string that selects this period again (links to the CSV
     * files, the print view).
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $jahr = $this->zeitraum->von->format('Y');

        return match ($this->art) {
            'jahr' => ['art' => 'jahr', 'jahr' => $jahr],
            'quartal' => ['art' => 'quartal', 'jahr' => $jahr, 'quartal' => (string) intdiv((int) $this->zeitraum->von->format('n') + 2, 3)],
            'monat' => ['art' => 'monat', 'jahr' => $jahr, 'monat' => $this->zeitraum->von->format('n')],
            default => ['art' => 'frei', 'von' => $this->zeitraum->von->format('Y-m-d'), 'bis' => $this->zeitraum->bis->format('Y-m-d')],
        };
    }

    private static function freiAusAnfrage(mixed $von, mixed $bis, int $letztesJahr, self $standard): ?self
    {
        $von = self::datum($von, $letztesJahr);
        $bis = self::datum($bis, $letztesJahr);
        if ($von === null || $bis === null || $bis < $von) {
            return null;
        }
        // Three whole calendar years still fit; one day more does not.
        if ($bis >= $von->modify('+' . self::MAX_JAHRE_FREI . ' years')) {
            return new self($standard->art, $standard->zeitraum, zuLang: true);
        }

        return self::frei($von, $bis);
    }

    private static function datum(mixed $wert, int $letztesJahr): ?\DateTimeImmutable
    {
        if (!is_string($wert) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert) !== 1) {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($datum === false || $datum->format('Y-m-d') !== $wert) {
            return null;
        }
        $jahr = (int) $datum->format('Y');

        return $jahr >= self::ERSTES_JAHR && $jahr <= $letztesJahr ? $datum : null;
    }
}
