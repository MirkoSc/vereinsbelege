<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Allocation;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionSetBy;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceType;

/**
 * Turns decrypted receipts and bookings into report lines (E-44). Pure - no
 * database, no vault; the caller decrypts.
 *
 * Receipt basis: the gross on the receipt's side, a credit note negative.
 *
 * Booking basis: transfers between own accounts (`doc_source = umbuchung`,
 * E-38) give no line at all. Otherwise every allocation to a checked
 * receipt becomes a line with that receipt's classification, in allocation
 * order and capped at what is left of the booking; a receipt of the other
 * direction (a refund of a supplier credit note) reduces the receipt's
 * side. What is left is a line with the booking's own category. So the
 * lines of a booking always add up to its amount in the balance.
 */
final class Postenbildung
{
    public static function ausBeleg(Belegklasse $beleg, int $brutto): Posten
    {
        return new Posten(
            $beleg->datum,
            $beleg->richtung,
            $beleg->typ === InvoiceType::Gutschrift ? -$brutto : $brutto,
            $beleg->kategorieId,
            $beleg->kostenstelleId,
            $beleg->lieferantId,
            $beleg->sphaere,
            belegId: $beleg->id,
        );
    }

    /**
     * @param list<Allocation> $zuordnungen the booking's allocations
     * @param array<int, Belegklasse> $belege by invoice id; an allocation to
     *        a receipt not in here (not checked yet) counts as unallocated
     *
     * @return list<Posten>
     */
    public static function ausBuchung(BankTransaction $buchung, array $zuordnungen, array $belege): array
    {
        if ($buchung->docSource === BankTransactionSetBy::Umbuchung || $buchung->amount === 0) {
            return [];
        }

        $seite = $buchung->amount > 0 ? InvoiceDirection::Einnahme : InvoiceDirection::Ausgabe;
        $rest = abs($buchung->amount);
        $posten = [];

        usort($zuordnungen, static fn(Allocation $a, Allocation $b): int => $a->id <=> $b->id);
        foreach ($zuordnungen as $zuordnung) {
            $beleg = $belege[$zuordnung->invoiceId] ?? null;
            $teil = min($zuordnung->amount, $rest);
            if ($beleg === null || $zuordnung->transactionId !== $buchung->id || $teil <= 0) {
                continue;
            }
            $rest -= $teil;
            $posten[] = new Posten(
                $buchung->bookingDate,
                $beleg->richtung,
                $beleg->richtung === $seite ? $teil : -$teil,
                $beleg->kategorieId ?? $buchung->categoryId,
                $beleg->kostenstelleId,
                $beleg->lieferantId,
                $beleg->sphaere,
                $buchung->accountId,
                $beleg->id,
                $buchung->id,
            );
        }

        if ($rest > 0) {
            $posten[] = new Posten(
                $buchung->bookingDate,
                $seite,
                $rest,
                $buchung->categoryId,
                kontoId: $buchung->accountId,
                buchungId: $buchung->id,
            );
        }

        return $posten;
    }
}
