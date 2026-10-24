<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;

/**
 * Which bookings are worth scoring against a receipt (M10-2, issue #66,
 * E-35) - so the matching never builds the cross product of all open
 * receipts and bookings. Built once per run from the decrypted bookings,
 * in memory of this request only.
 *
 * A booking is a candidate of a receipt when it runs in the receipt's
 * direction and currency and shares an anchor with it: the open amount
 * exactly or within the discount tolerance, a supplier IBAN (or, for a
 * reimbursement, the submitter's), a mandate reference or creditor id, the
 * invoice or customer number in purpose/end-to-end reference. Everything
 * else could only collect the name and date signals (Punktebewertung
 * accounts for that ceiling).
 *
 * An amount or discount range shared by more than BETRAG_MAX bookings
 * (monthly fees, membership dues) is not an anchor: it says nothing about
 * which booking it is. Those bookings are marked, so their ceiling includes
 * the amount points.
 */
final class Kandidatenindex
{
    /** Bookings one amount (or one discount range) may name before it stops being an anchor. */
    public const int BETRAG_MAX = 25;

    /** A number shorter than this is not searched (Punktebewertung::enthaeltKennung() agrees). */
    private const int KENNUNG_MIN = 3;

    /** @var array<string, int> key => bookings */
    private array $anzahl = [];

    /** @var array<string, array<int, list<int>>> key => open amount => booking ids */
    private array $nachBetrag = [];

    /** @var array<string, list<int>> key => sorted distinct open amounts */
    private array $betraege = [];

    /** @var array<string, array<string, list<int>>> */
    private array $nachIban = [];

    /** @var array<string, array<string, list<int>>> */
    private array $nachMandat = [];

    /** @var array<string, array<string, list<int>>> */
    private array $nachGlaeubiger = [];

    /** @var array<string, string> key => compact purposes, joined by "|" */
    private array $text = [];

    /** @var array<string, list<int>> key => start offset of each booking in $text */
    private array $starts = [];

    /** @var array<string, list<int>> key => booking id of each entry in $starts */
    private array $textIds = [];

    /** @var array<string, int> running length of $text while building */
    private array $laenge = [];

    /**
     * @param list<AbgleichBuchung> $buchungen
     */
    public function __construct(array $buchungen, private readonly Abgleichsparameter $p)
    {
        $teile = [];
        foreach ($buchungen as $b) {
            if ($b->offen <= 0) {
                continue;
            }
            $k = self::schluessel($b->richtung, $b->waehrung);
            $this->anzahl[$k] = ($this->anzahl[$k] ?? 0) + 1;
            $this->nachBetrag[$k][$b->offen][] = $b->id;
            if ($b->ibanNorm !== '') {
                $this->nachIban[$k][$b->ibanNorm][] = $b->id;
            }
            if ($b->mandatNorm !== '') {
                $this->nachMandat[$k][$b->mandatNorm][] = $b->id;
            }
            if ($b->glaeubigerNorm !== '') {
                $this->nachGlaeubiger[$k][$b->glaeubigerNorm][] = $b->id;
            }
            $laenge = isset($teile[$k]) ? $this->laenge[$k] : 0;
            $this->starts[$k][] = $laenge;
            $this->textIds[$k][] = $b->id;
            $teile[$k][] = $b->kompakt;
            $this->laenge[$k] = $laenge + strlen($b->kompakt) + 1;
        }
        foreach ($teile as $k => $liste) {
            $this->text[$k] = implode('|', $liste);
        }
        foreach ($this->nachBetrag as $k => $betraege) {
            $liste = array_keys($betraege);
            sort($liste);
            $this->betraege[$k] = $liste;
        }
    }

    public static function schluessel(BankTransactionDirection $richtung, string $waehrung): string
    {
        return $richtung->value . '|' . strtoupper($waehrung);
    }

    /** How many bookings with something open share this direction and currency. */
    public function anzahl(string $schluessel): int
    {
        return $this->anzahl[$schluessel] ?? 0;
    }

    /**
     * The candidate booking ids of $beleg and whether one of its amount
     * anchors was too common to use. Bookings in such a too common amount
     * are added to $betragGekappt (id => true).
     *
     * @param array<int, true> $betragGekappt
     *
     * @return array{0: list<int>, 1: bool}
     */
    public function kandidaten(AbgleichBeleg $beleg, array &$betragGekappt): array
    {
        $k = self::schluessel($beleg->buchungsrichtung, $beleg->waehrung);
        if (!isset($this->anzahl[$k]) || $beleg->offen <= 0) {
            return [[], false];
        }
        $ids = [];
        $gekappt = false;
        $nimm = static function (array $liste) use (&$ids): void {
            foreach ($liste as $id) {
                $ids[$id] = true;
            }
        };

        // Exact amount, then the discount range below it.
        $exakt = $this->nachBetrag[$k][$beleg->offen] ?? [];
        $skonto = [];
        foreach ($this->betraegeZwischen($k, Punktebewertung::skontoUntergrenze($beleg->offen, $this->p), $beleg->offen - 1) as $betrag) {
            array_push($skonto, ...$this->nachBetrag[$k][$betrag]);
        }
        foreach ([$exakt, $skonto] as $liste) {
            if (count($liste) <= self::BETRAG_MAX) {
                $nimm($liste);
            } else {
                $gekappt = true;
                foreach ($liste as $id) {
                    $betragGekappt[$id] = true;
                }
            }
        }

        foreach ([...$beleg->ibansNorm, ...($beleg->einreicherIbanNorm === '' ? [] : [$beleg->einreicherIbanNorm])] as $iban) {
            $nimm($this->nachIban[$k][$iban] ?? []);
        }
        foreach ($beleg->mandateNorm as $mandat) {
            $nimm($this->nachMandat[$k][$mandat] ?? []);
        }
        if ($beleg->glaeubigerNorm !== '') {
            $nimm($this->nachGlaeubiger[$k][$beleg->glaeubigerNorm] ?? []);
        }
        foreach ([$beleg->rechnungsnummer, $beleg->kundennummer] as $kennung) {
            $nimm($this->imText($k, Punktebewertung::kompakt($kennung)));
        }

        return [array_keys($ids), $gekappt];
    }

    /**
     * @return list<int> the distinct open amounts in [$von, $bis]
     */
    private function betraegeZwischen(string $k, int $von, int $bis): array
    {
        $liste = $this->betraege[$k] ?? [];
        if ($von > $bis || $liste === []) {
            return [];
        }
        $links = 0;
        $rechts = count($liste);
        while ($links < $rechts) {
            $mitte = intdiv($links + $rechts, 2);
            if ($liste[$mitte] < $von) {
                $links = $mitte + 1;
            } else {
                $rechts = $mitte;
            }
        }
        $treffer = [];
        for ($i = $links; $i < count($liste) && $liste[$i] <= $bis; $i++) {
            $treffer[] = $liste[$i];
        }

        return $treffer;
    }

    /**
     * Bookings whose compact purpose contains $nadel - a superset; the
     * boundaries are checked when the pair is scored.
     *
     * @return list<int>
     */
    private function imText(string $k, string $nadel): array
    {
        if (strlen($nadel) < self::KENNUNG_MIN || preg_match('/\d/', $nadel) !== 1 || !isset($this->text[$k])) {
            return [];
        }
        $text = $this->text[$k];
        $starts = $this->starts[$k];
        $ids = [];
        $pos = 0;
        while (($pos = strpos($text, $nadel, $pos)) !== false) {
            // The last entry starting at or before $pos.
            $links = 0;
            $rechts = count($starts) - 1;
            while ($links < $rechts) {
                $mitte = intdiv($links + $rechts + 1, 2);
                if ($starts[$mitte] <= $pos) {
                    $links = $mitte;
                } else {
                    $rechts = $mitte - 1;
                }
            }
            $ids[$this->textIds[$k][$links]] = true;
            $pos++;
        }

        return array_keys($ids);
    }
}
