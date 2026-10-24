<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Zahlungsweg;
use App\Service\MasterData\LieferantenAehnlichkeit;
use App\Service\MasterData\SupplierKeys;

/**
 * Scoring receipt <-> booking and the decision what is allocated by itself
 * (M10-2, issue #66, docs/spec/04-bank-und-abgleich.md section 5
 * "Punktebewertung", E-35). Pure functions on decrypted values - no vault,
 * no database -, so every signal and every threshold is tested on its own.
 *
 * - A pair is only scored when it could be allocated at all: the booking
 *   runs in the direction and currency the receipt expects and both still have something
 *   open. Everything else is not a candidate (null).
 * - Automatic only when the score reaches the threshold, both sides lead
 *   their runner-up by the distance - seen from the receipt AND from the
 *   booking - and the pair settles both completely (equal open amounts).
 *   Anything else at most a suggestion with its reasons.
 */
final class Punktebewertung
{
    /** Suggestions shown per receipt - the best ones. */
    public const int VORSCHLAEGE_JE_BELEG = 3;

    /** A number shorter than this ("1", "12") says nothing in a purpose. */
    private const int KENNUNG_MIN = 3;

    /** Entries the in-request name caches hold before they start over. */
    private const int MERKER_MAX = 20000;

    private function __construct()
    {
        // Static utility, no instances.
    }

    public static function bewerten(AbgleichBeleg $beleg, AbgleichBuchung $buchung, Abgleichsparameter $p): ?Bewertung
    {
        if ($buchung->richtung !== $beleg->buchungsrichtung
            || strtoupper($buchung->waehrung) !== strtoupper($beleg->waehrung)
            || $beleg->offen <= 0
            || $buchung->offen <= 0
        ) {
            return null;
        }

        $signale = [];
        $setze = static function (Signal $signal) use (&$signale, $p): void {
            $signale[$signal->value] = $p->punkte($signal);
        };

        // Amount: exact, or a little less (cash discount) - never more.
        if ($buchung->offen === $beleg->offen) {
            $setze(Signal::BetragExakt);
        } elseif ($buchung->offen < $beleg->offen && $buchung->offen >= self::skontoUntergrenze($beleg->offen, $p)) {
            $setze(Signal::BetragSkonto);
        }

        if (self::enthaeltKennung($buchung->suchtext, $beleg->rechnungsnummer)) {
            $setze(Signal::Rechnungsnummer);
        }

        if ($buchung->ibanNorm !== '' && in_array($buchung->ibanNorm, $beleg->ibansNorm, true)) {
            $setze(Signal::Iban);
        }

        if (($buchung->mandatNorm !== '' && in_array($buchung->mandatNorm, $beleg->mandateNorm, true))
            || ($buchung->glaeubigerNorm !== '' && $buchung->glaeubigerNorm === $beleg->glaeubigerNorm)
        ) {
            $setze(Signal::Mandat);
        }

        if (self::nameAehnlich($beleg->lieferantNamen, $buchung->gegenseiteName, $p->nameAehnlichkeitProzent)) {
            $setze(Signal::Name);
        }

        if (self::enthaeltKennung($buchung->suchtext, $beleg->kundennummer)) {
            $setze(Signal::Kundennummer);
        }

        $von = $beleg->rechnungsdatum->setTime(0, 0)->modify(sprintf('-%d days', $p->fensterVorTage));
        $bis = ($beleg->faelligkeit ?? $beleg->rechnungsdatum)->setTime(0, 0)->modify(sprintf('+%d days', $p->fensterNachTage));
        $tag = $buchung->buchungsdatum->setTime(0, 0);
        $setze($tag >= $von && $tag <= $bis ? Signal::DatumImFenster : Signal::DatumAusserhalb);

        if ($beleg->zahlungsweg === Zahlungsweg::Erstattung
            && $beleg->einreicherIbanNorm !== ''
            && $beleg->einreicherIbanNorm === $buchung->ibanNorm
            && $buchung->offen === $beleg->brutto
        ) {
            $setze(Signal::Erstattung);
        }

        return new Bewertung(
            $beleg->id,
            $buchung->id,
            array_sum($signale),
            $signale,
            min($beleg->offen, $buchung->offen),
            $beleg->offen === $buchung->offen,
        );
    }

    /** The least a booking may bring to count as "Skonto" for $offen. */
    public static function skontoUntergrenze(int $offen, Abgleichsparameter $p): int
    {
        return intdiv($offen * (100 - $p->skontoProzent) + 99, 100);
    }

    /**
     * Scores the candidate pairs and splits them into automatic allocations
     * and suggestions.
     *
     * Not every receipt is scored against every booking: Kandidatenindex
     * names the bookings sharing an anchor with the receipt (amount exact or
     * within the discount, IBAN, mandate/creditor id, invoice or customer
     * number in the purpose). A pair without an anchor can only collect the
     * name and the date signal; that ceiling stands in for all unscored
     * pairs of a side when the distance to the runner-up is checked, so
     * leaving them out never makes a pair look more unambiguous than it is.
     * An amount shared by too many bookings is not used as an anchor; the
     * ceiling of both sides then includes the amount points.
     *
     * A pair the user discarded (M10-4, E-41, $verworfen keyed
     * "invoiceId:transactionId") is not scored: it counts like an unscored
     * pair - only with the ceiling -, so it is never suggested or allocated
     * automatically, and the user's "no" no longer stands in the way of the
     * other candidates.
     *
     * @param list<AbgleichBeleg> $belege
     * @param list<AbgleichBuchung> $buchungen
     * @param array<string, true> $verworfen
     */
    public static function planen(array $belege, array $buchungen, Abgleichsparameter $p, array $verworfen = []): Abgleichsplan
    {
        $index = new Kandidatenindex($buchungen, $p);
        $nachId = [];
        foreach ($buchungen as $buchung) {
            $nachId[$buchung->id] = $buchung;
        }
        $basis = max(0, $p->punkte(Signal::Name)) + max($p->punkte(Signal::DatumImFenster), $p->punkte(Signal::DatumAusserhalb));
        $betragPunkte = max(0, $p->punkte(Signal::BetragExakt), $p->punkte(Signal::BetragSkonto));

        /** @var list<Bewertung> $alle */
        $alle = [];
        $jeBeleg = [];
        $jeBuchung = [];
        $obergrenzeBeleg = [];
        $belegeJeSchluessel = [];
        $betragGekappt = [];
        foreach ($belege as $beleg) {
            if ($beleg->offen <= 0) {
                continue;
            }
            $schluessel = Kandidatenindex::schluessel($beleg->buchungsrichtung, $beleg->waehrung);
            $belegeJeSchluessel[$schluessel] = ($belegeJeSchluessel[$schluessel] ?? 0) + 1;
            [$kandidaten, $gekappt] = $index->kandidaten($beleg, $betragGekappt);
            $jeBeleg[$beleg->id] = [];
            foreach ($kandidaten as $id) {
                if (isset($verworfen[$beleg->id . ':' . $id])) {
                    continue;
                }
                $bewertung = self::bewerten($beleg, $nachId[$id], $p);
                if ($bewertung === null) {
                    continue;
                }
                $alle[] = $bewertung;
                $jeBeleg[$beleg->id][] = $bewertung->score;
                $jeBuchung[$id][] = $bewertung->score;
            }
            if ($index->anzahl($schluessel) > count($jeBeleg[$beleg->id])) {
                $obergrenzeBeleg[$beleg->id] = $basis + ($gekappt ? $betragPunkte : 0);
            }
        }
        usort($alle, self::reihenfolge(...));

        $auto = [];
        $vergebeneBelege = [];
        $vergebeneBuchungen = [];
        foreach ($alle as $b) {
            if ($b->score < $p->autoSchwelle) {
                break;
            }
            if (!$b->vollGedeckt || isset($vergebeneBelege[$b->invoiceId]) || isset($vergebeneBuchungen[$b->transactionId])) {
                continue;
            }
            $buchung = $nachId[$b->transactionId];
            $belegSeite = $jeBeleg[$b->invoiceId];
            if (isset($obergrenzeBeleg[$b->invoiceId])) {
                $belegSeite[] = $obergrenzeBeleg[$b->invoiceId];
            }
            $buchungSeite = $jeBuchung[$b->transactionId];
            if (($belegeJeSchluessel[Kandidatenindex::schluessel($buchung->richtung, $buchung->waehrung)] ?? 0) > count($buchungSeite)) {
                $buchungSeite[] = $basis + (isset($betragGekappt[$b->transactionId]) ? $betragPunkte : 0);
            }
            if (!self::fuehrtMitAbstand($belegSeite, $b->score, $p->autoAbstand)
                || !self::fuehrtMitAbstand($buchungSeite, $b->score, $p->autoAbstand)
            ) {
                continue;
            }
            $auto[] = $b;
            $vergebeneBelege[$b->invoiceId] = true;
            $vergebeneBuchungen[$b->transactionId] = true;
        }

        $vorschlaege = [];
        $anzahl = [];
        foreach ($alle as $b) {
            if ($b->score < $p->vorschlagAb || isset($vergebeneBelege[$b->invoiceId]) || isset($vergebeneBuchungen[$b->transactionId])) {
                continue;
            }
            $anzahl[$b->invoiceId] = ($anzahl[$b->invoiceId] ?? 0) + 1;
            if ($anzahl[$b->invoiceId] <= self::VORSCHLAEGE_JE_BELEG) {
                $vorschlaege[] = $b;
            }
        }

        return new Abgleichsplan($auto, $vorschlaege);
    }

    /**
     * Whether $score leads every other candidate of the same side by at
     * least $abstand. $scores holds $score itself once (and possibly the
     * ceiling of the unscored pairs); a tie never leads.
     *
     * @param list<int> $scores
     */
    public static function fuehrtMitAbstand(array $scores, int $score, int $abstand): bool
    {
        rsort($scores);
        $index = array_search($score, $scores, true);
        if ($index !== 0) {
            return false;
        }

        return !isset($scores[1]) || ($score > $scores[1] && $score - $scores[1] >= $abstand);
    }

    /**
     * Lower case, umlauts spelled out, letters and digits only: "RE-2026/0815"
     * and "re 2026 0815" are the same.
     */
    public static function kompakt(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', SupplierKeys::umschrift($text));
    }

    /**
     * The purpose as enthaeltKennung() searches it: lower case, umlauts
     * spelled out, every run of other characters one space - separators stay
     * visible, so "15.03.2026 4711" does not turn into "...20264711".
     */
    public static function suchtext(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', SupplierKeys::umschrift($text)));
    }

    /**
     * Whether $kennung (an invoice or customer number) occurs in $text (from
     * suchtext()), separators inside it ignored: "RE-2026/0815" is found in
     * "re 2026 0815". It needs a digit and three characters; a number edge
     * must not touch another digit directly - "815" is not found in
     * "20260815", but in "2026 815".
     */
    public static function enthaeltKennung(string $text, string $kennung): bool
    {
        $nadel = self::kompakt($kennung);
        if (strlen($nadel) < self::KENNUNG_MIN || preg_match('/\d/', $nadel) !== 1) {
            return false;
        }

        $muster = implode(' ?', str_split($nadel));
        $davor = ctype_digit($nadel[0]) ? '(?<![0-9])' : '';
        $danach = ctype_digit($nadel[strlen($nadel) - 1]) ? '(?![0-9])' : '';

        return preg_match('/' . $davor . $muster . $danach . '/', $text) === 1;
    }

    /**
     * @param list<string> $namen
     */
    private static function nameAehnlich(array $namen, string $gegenseite, int $prozent): bool
    {
        $gegen = self::namensschluessel($gegenseite);
        if ($gegen === '') {
            return false;
        }
        foreach ($namen as $name) {
            if (round(self::aehnlichkeit(self::namensschluessel($name), $gegen) * 100, 6) >= $prozent) {
                return true;
            }
        }

        return false;
    }

    /**
     * SupplierKeys::name(), remembered within the request - the same names
     * come up for every pair.
     */
    private static function namensschluessel(string $name): string
    {
        static $merker = [];
        if (count($merker) > self::MERKER_MAX) {
            $merker = [];
        }

        return $merker[$name] ??= SupplierKeys::name($name);
    }

    private static function aehnlichkeit(string $a, string $b): float
    {
        static $merker = [];
        if (count($merker) > self::MERKER_MAX) {
            $merker = [];
        }

        return $merker[$a . "\0" . $b] ??= LieferantenAehnlichkeit::wert($a, $b);
    }

    /** Best first; ties by receipt, then booking id - the same input gives the same plan. */
    private static function reihenfolge(Bewertung $a, Bewertung $b): int
    {
        return $b->score <=> $a->score ?: $a->invoiceId <=> $b->invoiceId ?: $a->transactionId <=> $b->transactionId;
    }
}
