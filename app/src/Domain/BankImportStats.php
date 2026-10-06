<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The counts of a statement import (`bank_import.stats`, M9-4, issue #62).
 * Counts only - never an amount, a name or an IBAN: the column is plaintext.
 *
 * - gesamt: bookings the file carries (readable rows, pending ones not
 *   included)
 * - neu / duplikat: of those, written by this import / already stored
 *   before (overlapping or repeated export)
 * - fehler: CSV rows that did not fit the format - not imported
 * - vorgemerkt: CSV rows still pending at the bank - skipped, they come
 *   again with the next export
 * - vorStichtag: bookings before the account's opening date - imported, but
 *   outside the balance from the opening date on
 */
final readonly class BankImportStats
{
    public function __construct(
        public int $gesamt = 0,
        public int $neu = 0,
        public int $duplikat = 0,
        public int $fehler = 0,
        public int $vorgemerkt = 0,
        public int $vorStichtag = 0,
    ) {
    }

    /**
     * @param array<mixed> $werte
     */
    public static function fromArray(array $werte): self
    {
        $zahl = static fn(string $name): int => is_int($werte[$name] ?? null) ? $werte[$name] : 0;

        return new self(
            gesamt: $zahl('gesamt'),
            neu: $zahl('neu'),
            duplikat: $zahl('duplikat'),
            fehler: $zahl('fehler'),
            vorgemerkt: $zahl('vorgemerkt'),
            vorStichtag: $zahl('vor_stichtag'),
        );
    }

    /**
     * @return array{gesamt: int, neu: int, duplikat: int, fehler: int, vorgemerkt: int, vor_stichtag: int}
     */
    public function toArray(): array
    {
        return [
            'gesamt' => $this->gesamt,
            'neu' => $this->neu,
            'duplikat' => $this->duplikat,
            'fehler' => $this->fehler,
            'vorgemerkt' => $this->vorgemerkt,
            'vor_stichtag' => $this->vorStichtag,
        ];
    }
}
