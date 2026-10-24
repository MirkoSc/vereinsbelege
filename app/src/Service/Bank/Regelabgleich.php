<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\AssignmentRule;
use App\Domain\Belegmerkmale;
use App\Domain\Buchungsmerkmale;
use App\Domain\Iban;

/**
 * Whether a rule matches a booking (M9-6, issue #64, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M9-6"). Plain PHP on decrypted
 * values, no database:
 *
 * - `stichwort`: contained in purpose or booking text, ignoring case and
 *   runs of white space ("zinsen" finds "Abschluss  ZINSEN 3/26").
 * - `gegenseite`: a valid IBAN equals the counterparty IBAN; anything else
 *   is contained in the counterparty name, ignoring case and white space.
 * - Both given: both must match. Neither given: nothing matches - a rule
 *   without a condition would swallow every booking.
 * - A direction, if given, must be the booking's.
 *
 * Of several active rules the oldest (lowest id) wins, for both of its
 * effects - one rule per booking keeps "which rule did this" a single
 * answer.
 *
 * The same rules suggest the category of a receipt (M7-7, issue #49,
 * docs/spec/03-erfassung-und-ki.md section 8, trifftBeleg()): the keyword in
 * the receipt's purpose, the counterparty against the supplier's IBANs or
 * its name and aliases.
 */
final class Regelabgleich
{
    private function __construct()
    {
        // Static utility, no instances.
    }

    /** Lower case, white space collapsed to single blanks, trimmed - how patterns and texts are compared. */
    public static function normalisieren(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    public static function trifft(AssignmentRule $regel, Buchungsmerkmale $buchung): bool
    {
        if ($regel->stichwort === '' && $regel->gegenseite === '') {
            return false;
        }
        if ($regel->direction !== null && $regel->direction !== $buchung->richtung) {
            return false;
        }

        if ($regel->stichwort !== '') {
            $stichwort = self::normalisieren($regel->stichwort);
            if (!str_contains(self::normalisieren($buchung->zweck), $stichwort)
                && !str_contains(self::normalisieren($buchung->buchungstext), $stichwort)) {
                return false;
            }
        }

        if ($regel->gegenseite !== '') {
            $treffer = $regel->gegenseiteIstIban()
                ? $buchung->gegenseiteIban !== '' && Iban::normalisieren($buchung->gegenseiteIban) === Iban::normalisieren($regel->gegenseite)
                : str_contains(self::normalisieren($buchung->gegenseiteName), self::normalisieren($regel->gegenseite));
            if (!$treffer) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the rule matches a receipt (M7-7, issue #49): `stichwort`
     * contained in the purpose; `gegenseite` an IBAN equal to one of the
     * partner's, anything else contained in its name or one of its aliases.
     * Same normalisation, same "both must match" and "no condition matches
     * nothing" as for a booking.
     */
    public static function trifftBeleg(AssignmentRule $regel, Belegmerkmale $beleg): bool
    {
        if ($regel->stichwort === '' && $regel->gegenseite === '') {
            return false;
        }
        if ($regel->direction !== null && $regel->direction->value !== $beleg->richtung->value) {
            return false;
        }
        if ($regel->stichwort !== '' && !str_contains(self::normalisieren($beleg->zweck), self::normalisieren($regel->stichwort))) {
            return false;
        }
        if ($regel->gegenseite === '') {
            return true;
        }

        if ($regel->gegenseiteIstIban()) {
            $iban = Iban::normalisieren($regel->gegenseite);
            foreach ($beleg->partnerIbans as $partnerIban) {
                if (Iban::normalisieren($partnerIban) === $iban) {
                    return true;
                }
            }

            return false;
        }

        $muster = self::normalisieren($regel->gegenseite);
        foreach ($beleg->partnerNamen as $name) {
            if (str_contains(self::normalisieren($name), $muster)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The active rule that applies to the booking: the oldest one that
     * matches, or null.
     *
     * @param list<AssignmentRule> $regeln in any order
     */
    public static function ersteTreffende(array $regeln, Buchungsmerkmale $buchung): ?AssignmentRule
    {
        usort($regeln, static fn(AssignmentRule $a, AssignmentRule $b): int => $a->id <=> $b->id);
        foreach ($regeln as $regel) {
            if ($regel->active && self::trifft($regel, $buchung)) {
                return $regel;
            }
        }

        return null;
    }
}
