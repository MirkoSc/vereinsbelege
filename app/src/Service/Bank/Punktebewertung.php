<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\Iban;
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
 *   runs in the direction the receipt expects and both still have something
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

    private function __construct()
    {
        // Static utility, no instances.
    }

    public static function bewerten(AbgleichBeleg $beleg, AbgleichBuchung $buchung, Abgleichsparameter $p): ?Bewertung
    {
        if ($buchung->richtung !== $beleg->buchungsrichtung || $beleg->offen <= 0 || $buchung->offen <= 0) {
            return null;
        }

        $signale = [];
        $setze = static function (Signal $signal) use (&$signale, $p): void {
            $signale[$signal->value] = $p->punkte($signal);
        };

        // Amount: exact, or a little less (cash discount) - never more.
        if ($buchung->offen === $beleg->offen) {
            $setze(Signal::BetragExakt);
        } elseif ($buchung->offen < $beleg->offen && $buchung->offen * 100 >= $beleg->offen * (100 - $p->skontoProzent)) {
            $setze(Signal::BetragSkonto);
        }

        $zweck = self::kompakt($buchung->verwendungszweck . ' ' . $buchung->endToEndReferenz);
        if (self::enthaeltKennung($zweck, $beleg->rechnungsnummer)) {
            $setze(Signal::Rechnungsnummer);
        }

        $iban = Iban::normalisieren($buchung->gegenseiteIban);
        if ($iban !== '' && in_array($iban, array_map(Iban::normalisieren(...), $beleg->lieferantIbans), true)) {
            $setze(Signal::Iban);
        }

        if (self::mandatBekannt($beleg, $buchung)) {
            $setze(Signal::Mandat);
        }

        if (self::nameAehnlich($beleg->lieferantNamen, $buchung->gegenseiteName, $p->nameAehnlichkeitProzent)) {
            $setze(Signal::Name);
        }

        if (self::enthaeltKennung($zweck, $beleg->kundennummer)) {
            $setze(Signal::Kundennummer);
        }

        $von = $beleg->rechnungsdatum->setTime(0, 0)->modify(sprintf('-%d days', $p->fensterVorTage));
        $bis = ($beleg->faelligkeit ?? $beleg->rechnungsdatum)->setTime(0, 0)->modify(sprintf('+%d days', $p->fensterNachTage));
        $tag = $buchung->buchungsdatum->setTime(0, 0);
        $setze($tag >= $von && $tag <= $bis ? Signal::DatumImFenster : Signal::DatumAusserhalb);

        if ($beleg->zahlungsweg === Zahlungsweg::Erstattung
            && $beleg->einreicherIban !== null
            && $iban !== ''
            && Iban::normalisieren($beleg->einreicherIban) === $iban
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

    /**
     * Scores every pair and splits them into automatic allocations and
     * suggestions.
     *
     * @param list<AbgleichBeleg> $belege
     * @param list<AbgleichBuchung> $buchungen
     */
    public static function planen(array $belege, array $buchungen, Abgleichsparameter $p): Abgleichsplan
    {
        /** @var list<Bewertung> $alle */
        $alle = [];
        /** @var array<int, list<int>> $jeBeleg */
        $jeBeleg = [];
        /** @var array<int, list<int>> $jeBuchung */
        $jeBuchung = [];
        foreach ($belege as $beleg) {
            foreach ($buchungen as $buchung) {
                $bewertung = self::bewerten($beleg, $buchung, $p);
                if ($bewertung === null) {
                    continue;
                }
                $alle[] = $bewertung;
                $jeBeleg[$beleg->id][] = $bewertung->score;
                $jeBuchung[$buchung->id][] = $bewertung->score;
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
            if (!self::fuehrtMitAbstand($jeBeleg[$b->invoiceId], $b->score, $p->autoAbstand)
                || !self::fuehrtMitAbstand($jeBuchung[$b->transactionId], $b->score, $p->autoAbstand)
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
     * least $abstand. $scores holds $score itself once; a tie never leads.
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

        return !isset($scores[1]) || $score - $scores[1] >= $abstand;
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
     * Whether $kennung (an invoice or customer number) occurs in the compact
     * $text. It needs a digit and three characters; a number edge must not
     * touch another digit - "815" is not found in "20260815".
     */
    public static function enthaeltKennung(string $text, string $kennung): bool
    {
        $nadel = self::kompakt($kennung);
        if (strlen($nadel) < self::KENNUNG_MIN || preg_match('/\d/', $nadel) !== 1) {
            return false;
        }

        $start = 0;
        while (($pos = strpos($text, $nadel, $start)) !== false) {
            $davor = $pos > 0 ? $text[$pos - 1] : '';
            $danach = $text[$pos + strlen($nadel)] ?? '';
            $randDavor = ctype_digit($nadel[0]) && $davor !== '' && ctype_digit($davor);
            $randDanach = ctype_digit($nadel[strlen($nadel) - 1]) && $danach !== '' && ctype_digit($danach);
            if (!$randDavor && !$randDanach) {
                return true;
            }
            $start = $pos + 1;
        }

        return false;
    }

    private static function mandatBekannt(AbgleichBeleg $beleg, AbgleichBuchung $buchung): bool
    {
        $mandat = SupplierKeys::kennung($buchung->mandatsreferenz);
        if ($mandat !== '' && in_array($mandat, array_map(SupplierKeys::kennung(...), $beleg->mandatsreferenzen), true)) {
            return true;
        }
        $glaeubiger = SupplierKeys::kennung($buchung->glaeubigerId);

        return $glaeubiger !== '' && $glaeubiger === SupplierKeys::kennung($beleg->glaeubigerId);
    }

    /**
     * @param list<string> $namen
     */
    private static function nameAehnlich(array $namen, string $gegenseite, int $prozent): bool
    {
        $gegen = SupplierKeys::name($gegenseite);
        if ($gegen === '') {
            return false;
        }
        foreach ($namen as $name) {
            if (LieferantenAehnlichkeit::wert(SupplierKeys::name($name), $gegen) * 100 >= $prozent) {
                return true;
            }
        }

        return false;
    }

    /** Best first; ties by receipt, then booking id - the same input gives the same plan. */
    private static function reihenfolge(Bewertung $a, Bewertung $b): int
    {
        return $b->score <=> $a->score ?: $a->invoiceId <=> $b->invoiceId ?: $a->transactionId <=> $b->transactionId;
    }
}
