<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The completeness report of the Kassenprüfung (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1): all bookings of the period with
 * their receipt status, the balance of every account at its start and end,
 * the bookings without a receipt and the cases decided by hand.
 * Decrypted - only in a session with an unlocked vault.
 */
final readonly class KassenpruefungBericht
{
    /**
     * @param list<KontosaldoZeile> $salden in the order of the accounts
     * @param list<Pruefbuchung> $buchungen by booking date, then id
     */
    public function __construct(
        /** The period asked for. */
        public Zeitraum $zeitraum,
        /** The part of it the reader may see; null = nothing (outside the released period). */
        public ?Zeitraum $sichtbar,
        public array $salden,
        public array $buchungen,
        /**
         * The period scope cuts the start: the opening balance is the
         * carry-over of everything before the released period (E-44),
         * not a sum the reader could check against single bookings.
         */
        public bool $uebertrag = false,
        /** Bookings or accounts that could not be decrypted - left out, never guessed. */
        public int $unlesbar = 0,
    ) {
    }

    /** @return list<Pruefbuchung> */
    public function ohneBeleg(): array
    {
        return array_values(array_filter($this->buchungen, static fn(Pruefbuchung $b): bool => $b->fehltBeleg()));
    }

    /** @return list<Pruefbuchung> */
    public function manuelleFaelle(): array
    {
        return array_values(array_filter($this->buchungen, static fn(Pruefbuchung $b): bool => $b->istManuellerFall()));
    }

    /**
     * Bookings with an allocation that could not be decrypted: their
     * receipt status cannot be checked from here.
     */
    public function zuordnungUnlesbar(): int
    {
        return count(array_filter($this->buchungen, static fn(Pruefbuchung $b): bool => $b->zuordnungUnlesbar));
    }
}
