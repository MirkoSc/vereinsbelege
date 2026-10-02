<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * A CSV export read with a profile: the booked transactions in file order,
 * the rows that could not be read, and how many pending ("vorgemerkt")
 * rows were left out. Value objects only - nothing is stored or logged.
 */
final readonly class CsvErgebnis
{
    /**
     * @param list<string>          $kopf
     * @param list<CsvBuchung>      $buchungen
     * @param list<CsvZeilenfehler> $fehler
     */
    public function __construct(
        public array $kopf,
        public int $kopfzeile,
        public array $buchungen,
        public array $fehler,
        public int $vorgemerkt,
    ) {
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null earliest and
     *         latest booking date
     */
    public function zeitraum(): ?array
    {
        if ($this->buchungen === []) {
            return null;
        }
        $daten = array_map(static fn (CsvBuchung $b): \DateTimeImmutable => $b->umsatz->buchungsdatum, $this->buchungen);

        return [min($daten), max($daten)];
    }
}
