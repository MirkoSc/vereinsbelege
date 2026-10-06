<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Service\Bank\Csv\CsvZeilenfehler;
use App\Service\Bank\Kontoangabe;
use App\Service\Bank\Kontoauszug;

/**
 * A statement file as App\Service\Bank\Import\KontoauszugLeser read it -
 * MT940 and CSV in one shape (M9-4, issue #62). Pure values; nothing is
 * stored or logged.
 *
 * `posten` is in chronological order: a CSV export written newest first is
 * turned round, so the step chain writes oldest first and the balance
 * column reads as a chain.
 */
final readonly class ImportDatei
{
    /**
     * @param ?Kontoangabe $konto the account line of an MT940 file; a CSV
     *        file names none
     * @param list<ImportPosten> $posten
     * @param list<Kontoauszug> $auszuege the MT940 statements, in file order
     * @param list<CsvZeilenfehler> $fehler CSV rows that did not fit the
     *        profile - not imported
     * @param int $vorgemerkt CSV rows still pending at the bank - skipped
     * @param bool $saldoNachBuchung every posting carries the balance after it
     */
    public function __construct(
        public ImportFormat $format,
        public ?Kontoangabe $konto,
        public array $posten,
        public array $auszuege,
        public array $fehler,
        public int $vorgemerkt,
        public bool $saldoNachBuchung,
    ) {
    }

    /**
     * Earliest and latest booking date, or null for a file without bookings.
     *
     * @return ?array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public function zeitraum(): ?array
    {
        if ($this->posten === []) {
            return null;
        }
        $daten = array_map(static fn(ImportPosten $p): \DateTimeImmutable => $p->umsatz->buchungsdatum, $this->posten);

        return [min($daten), max($daten)];
    }

    /** Whether the file says anything about balances at all. */
    public function hatSalden(): bool
    {
        return $this->format->istMt940() ? $this->auszuege !== [] : $this->saldoNachBuchung && $this->posten !== [];
    }
}
