<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankTransaction;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;

/**
 * One booking in the Kassenprüfung (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1: "alle Buchungen mit Belegstatus"),
 * decrypted, with its account name and its allocations.
 */
final readonly class Pruefbuchung
{
    /**
     * @param list<Pruefzuordnung> $zuordnungen in allocation order
     * @param bool $zuordnungUnlesbar an allocation of it could not be decrypted
     */
    public function __construct(
        public BankTransaction $buchung,
        public string $konto,
        public array $zuordnungen,
        public bool $zuordnungUnlesbar = false,
    ) {
    }

    /** Transfer between own accounts (E-38): neither income nor expense, needs no receipt of its own. */
    public function istUmbuchung(): bool
    {
        return $this->buchung->docSource === BankTransactionSetBy::Umbuchung;
    }

    /** The sum of the allocations in cents (always positive). */
    public function zugeordnet(): int
    {
        return array_sum(array_map(static fn(Pruefzuordnung $z): int => $z->zuordnung->amount, $this->zuordnungen));
    }

    /**
     * What still lacks a receipt, in cents: the whole amount or the
     * uncovered rest of a booking that needs one (status `fehlt` or
     * `teilweise`), else 0.
     */
    public function ohneBeleg(): int
    {
        if ($this->buchung->docStatus !== BankTransactionDocStatus::Fehlt && $this->buchung->docStatus !== BankTransactionDocStatus::Teilweise) {
            return 0;
        }

        return max(0, abs($this->buchung->amount) - $this->zugeordnet());
    }

    /** Belongs on the list "ohne Beleg". */
    public function fehltBeleg(): bool
    {
        return $this->buchung->docStatus === BankTransactionDocStatus::Fehlt || $this->buchung->docStatus === BankTransactionDocStatus::Teilweise;
    }

    /** A person declared that this booking needs no receipt (and no rule or default did). */
    public function vonHandOhneBelegpflicht(): bool
    {
        return $this->buchung->docStatus === BankTransactionDocStatus::NichtNoetig && $this->buchung->docSource === BankTransactionSetBy::Manuell;
    }

    /** @return list<Pruefzuordnung> the allocations made by hand */
    public function zuordnungenVonHand(): array
    {
        return array_values(array_filter($this->zuordnungen, static fn(Pruefzuordnung $z): bool => $z->vonHand()));
    }

    /**
     * A "manuell zugeordneter Fall" (05 section 1): a receipt allocated by
     * hand, or "kein Beleg nötig" set by a person.
     */
    public function istManuellerFall(): bool
    {
        return $this->vonHandOhneBelegpflicht() || $this->zuordnungenVonHand() !== [];
    }
}
