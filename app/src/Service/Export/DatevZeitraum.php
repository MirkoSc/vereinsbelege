<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * The period of a DATEV export (issue #93, E-49): booking date from/to,
 * both inclusive. A Buchungsstapel covers at most one financial year
 * (= calendar year, E-16), so both dates must lie in the same year.
 * Without dates the current year.
 */
final readonly class DatevZeitraum
{
    public function __construct(
        public \DateTimeImmutable $von,
        public \DateTimeImmutable $bis,
        private bool $gueltigeEingabe = true,
    ) {
    }

    /** @param array<string, mixed> $felder query string or form */
    public static function ausFeldern(array $felder, \DateTimeImmutable $heute): self
    {
        $jahr = (int) $heute->format('Y');
        $standardVon = $heute->setDate($jahr, 1, 1)->setTime(0, 0);
        $standardBis = $heute->setDate($jahr, 12, 31)->setTime(0, 0);
        $datum = static function (mixed $wert): ?\DateTimeImmutable {
            if (!is_string($wert)) {
                return null;
            }
            $tag = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($wert));

            return $tag !== false && $tag->format('Y-m-d') === trim($wert) ? $tag : null;
        };

        if (!array_key_exists('von', $felder) && !array_key_exists('bis', $felder)) {
            return new self($standardVon, $standardBis);
        }
        $von = $datum($felder['von'] ?? null);
        $bis = $datum($felder['bis'] ?? null);

        return new self($von ?? $standardVon, $bis ?? $standardBis, $von !== null && $bis !== null);
    }

    /** What is wrong with the period, in German - null if nothing. */
    public function fehler(): ?string
    {
        return match (true) {
            !$this->gueltigeEingabe => 'Bitte ein gültiges Von- und Bis-Datum angeben.',
            $this->von > $this->bis => 'Das Von-Datum liegt nach dem Bis-Datum.',
            $this->von->format('Y') !== $this->bis->format('Y') => 'Ein DATEV-Buchungsstapel umfasst höchstens ein Geschäftsjahr. Bitte einen Zeitraum innerhalb eines Kalenderjahres wählen.',
            default => null,
        };
    }

    /** @return array{von: string, bis: string} */
    public function alsFelder(): array
    {
        return ['von' => $this->von->format('Y-m-d'), 'bis' => $this->bis->format('Y-m-d')];
    }

    /**
     * Download name: the period only, no business data -
     * "EXTF_Buchungsstapel_2026.csv" for a whole year.
     */
    public function dateiname(): string
    {
        $jahr = $this->von->format('Y');
        $zeitraum = $this->von->format('m-d') === '01-01' && $this->bis->format('m-d') === '12-31' && $this->bis->format('Y') === $jahr
            ? $jahr
            : $this->von->format('Y-m-d') . '_bis_' . $this->bis->format('Y-m-d');

        return 'EXTF_Buchungsstapel_' . $zeitraum . '.csv';
    }
}
