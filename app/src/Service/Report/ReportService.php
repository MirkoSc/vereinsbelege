<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;
use App\Domain\InvoiceDirection;
use App\Domain\Zugriffsbereich;

/**
 * All report figures (docs/spec/05-auswertung-und-export.md section 1,
 * issue #71, E-44): sums, groups by category/supplier/cost center/sphere/
 * month/account, top lists, drilldown, comparison with the previous year
 * and account balances.
 *
 * Pure calculation on decrypted report lines - no database, no session, no
 * vault (App\Service\Report\Auswertungsdaten loads and decrypts). Integer
 * cents throughout, never a float.
 *
 * Every method narrows the lines again to the period of the input and to
 * the reader's Zugriffsbereich (cost center and period scope, docs/spec/
 * 01-sicherheit.md section 4) - the loader already filters in SQL, this is
 * the same rule once more, so a caller passing too much cannot show it.
 * A line without a cost center is outside every cost-center scope, as in
 * the repositories. The sphere is only reported with `sphaeren_aktiv` (E-15).
 */
final readonly class ReportService
{
    public function __construct(private bool $sphaerenAktiv = false)
    {
    }

    public function summen(Auswertungsgrundlage $grundlage, Zugriffsbereich $bereich, ?InvoiceDirection $nur = null): Summen
    {
        $summen = new Summen();
        foreach ($this->relevant($grundlage, $bereich, $nur) as $posten) {
            $summen = $summen->mit($posten);
        }

        return $summen;
    }

    /**
     * The lines grouped by $dimension. Months in calendar order, every month
     * of the period included (a gap is a zero, not a missing bar); all
     * other dimensions largest first (income plus expenses), the group
     * without a key last.
     *
     * @param InvoiceDirection|null $nur only income or only expenses
     *        ("Einnahmen nach Kategorie")
     *
     * @return list<Gruppe>
     */
    public function gruppiert(Auswertungsgrundlage $grundlage, Zugriffsbereich $bereich, Dimension $dimension, ?InvoiceDirection $nur = null): array
    {
        $this->pruefeDimension($dimension);

        /** @var array<string, array{0: int|string|null, 1: Summen}> $gruppen */
        $gruppen = [];
        $sichtbar = $grundlage->zeitraum->begrenztAuf($bereich);
        if ($dimension === Dimension::Monat && $sichtbar !== null) {
            // Only the months the reader may see - not zeros for the others.
            foreach ($sichtbar->monate() as $monat) {
                $gruppen[self::index($monat)] = [$monat, new Summen()];
            }
        }
        foreach ($this->relevant($grundlage, $bereich, $nur) as $posten) {
            $schluessel = $dimension->schluessel($posten);
            $index = self::index($schluessel);
            $gruppen[$index] = [$schluessel, ($gruppen[$index][1] ?? new Summen())->mit($posten)];
        }

        $liste = array_map(static fn(array $g): Gruppe => new Gruppe($g[0], $g[1]), array_values($gruppen));
        if ($dimension === Dimension::Monat) {
            usort($liste, static fn(Gruppe $a, Gruppe $b): int => (string) $a->schluessel <=> (string) $b->schluessel);
        } else {
            usort($liste, self::vergleicheGruppen(...));
        }

        return $liste;
    }

    /**
     * The $anzahl largest groups with a key (e.g. Top 20 suppliers), the
     * rest summed, the lines without a key on their own.
     */
    public function rangliste(Auswertungsgrundlage $grundlage, Zugriffsbereich $bereich, Dimension $dimension, int $anzahl = 20, ?InvoiceDirection $nur = null): Rangliste
    {
        if ($anzahl < 1) {
            throw new \InvalidArgumentException('Eine Rangliste braucht mindestens einen Platz.');
        }

        $mit = [];
        $ohne = null;
        foreach ($this->gruppiert($grundlage, $bereich, $dimension, $nur) as $gruppe) {
            if ($gruppe->schluessel === null) {
                $ohne = $gruppe->summen;
            } else {
                $mit[] = $gruppe;
            }
        }
        if ($dimension === Dimension::Monat) {
            usort($mit, self::vergleicheGruppen(...));
        }

        $uebrige = null;
        foreach (array_slice($mit, $anzahl) as $gruppe) {
            $uebrige = ($uebrige ?? new Summen())->plus($gruppe->summen);
        }

        return new Rangliste(array_slice($mit, 0, $anzahl), $uebrige, $ohne);
    }

    /**
     * The lines behind one group ("Drilldown bis zum Beleg"), by date then
     * receipt and booking id. $schluessel null = the lines without a key;
     * an id may come as a string from a request (`'12'`).
     *
     * @return list<Posten>
     */
    public function drilldown(Auswertungsgrundlage $grundlage, Zugriffsbereich $bereich, Dimension $dimension, int|string|null $schluessel, ?InvoiceDirection $nur = null): array
    {
        $this->pruefeDimension($dimension);
        $schluessel = $dimension->normalisiere($schluessel);

        $treffer = array_values(array_filter(
            $this->relevant($grundlage, $bereich, $nur),
            static fn(Posten $p): bool => $dimension->schluessel($p) === $schluessel,
        ));
        usort($treffer, static fn(Posten $a, Posten $b): int => [$a->datum->format('Y-m-d'), $a->belegId ?? 0, $a->buchungId ?? 0]
            <=> [$b->datum->format('Y-m-d'), $b->belegId ?? 0, $b->buchungId ?? 0]);

        return $treffer;
    }

    /**
     * Current period against the previous year ("Vorjahresvergleich"). With
     * a dimension one row per key of either period, in the order of the
     * current period (keys only the previous year has follow, largest
     * first); without one, a single row for the totals (key null). Month
     * keys of the previous year are moved forward by one year.
     *
     * @return list<Vergleichszeile>
     */
    public function vergleich(Auswertungsgrundlage $aktuell, Auswertungsgrundlage $vorjahr, Zugriffsbereich $bereich, ?Dimension $dimension = null, ?InvoiceDirection $nur = null): array
    {
        if ($aktuell->grundlage !== $vorjahr->grundlage) {
            throw new \InvalidArgumentException('Ein Vergleich braucht beide Male dieselbe Grundlage.');
        }
        if ($dimension === null) {
            return [new Vergleichszeile(null, $this->summen($aktuell, $bereich, $nur), $this->summen($vorjahr, $bereich, $nur))];
        }

        $zeilen = [];
        foreach ($this->gruppiert($aktuell, $bereich, $dimension, $nur) as $gruppe) {
            $zeilen[self::index($gruppe->schluessel)] = [$gruppe->schluessel, $gruppe->summen, new Summen()];
        }
        foreach ($this->gruppiert($vorjahr, $bereich, $dimension, $nur) as $gruppe) {
            $schluessel = $dimension === Dimension::Monat ? self::monatPlusJahr((string) $gruppe->schluessel) : $gruppe->schluessel;
            $index = self::index($schluessel);
            $zeilen[$index] = [$schluessel, $zeilen[$index][1] ?? new Summen(), $gruppe->summen];
        }

        $liste = array_map(static fn(array $z): Vergleichszeile => new Vergleichszeile($z[0], $z[1], $z[2]), array_values($zeilen));
        if ($dimension === Dimension::Monat) {
            usort($liste, static fn(Vergleichszeile $a, Vergleichszeile $b): int => (string) $a->schluessel <=> (string) $b->schluessel);
        } else {
            // Stable: current order, then keys only the previous year has; "ohne" last.
            usort($liste, static fn(Vergleichszeile $a, Vergleichszeile $b): int => ($a->schluessel === null) <=> ($b->schluessel === null));
        }

        return $liste;
    }

    /**
     * Balance of each account at the end of $stichtag (Dashboard
     * "Kontostände je Konto"): the opening balance plus all bookings from
     * the opening date on - transfers included (E-38). Accounts opened after
     * $stichtag are left out. Bookings carry no cost center, so a
     * cost-center scope sees no balances; a period scope sees only balances
     * at a day inside its period.
     *
     * @param list<BankAccount> $konten
     * @param list<Kontobewegung> $bewegungen
     *
     * @return array<int, int> account id => cents
     */
    public function kontostaende(array $konten, array $bewegungen, \DateTimeImmutable $stichtag, Zugriffsbereich $bereich): array
    {
        if ($bereich->kostenstellen !== null || Zeitraum::frei($stichtag, $stichtag)->begrenztAuf($bereich) === null) {
            return [];
        }

        $tag = self::ymd($stichtag);
        $eroeffnet = self::eroeffnungen($konten);
        $stand = [];
        foreach ($konten as $konto) {
            if ($eroeffnet[$konto->id] <= $tag) {
                $stand[$konto->id] = $konto->openingBalance;
            }
        }
        foreach ($bewegungen as $b) {
            $datum = self::ymd($b->datum);
            if (isset($stand[$b->kontoId]) && $datum <= $tag && $datum >= $eroeffnet[$b->kontoId]) {
                $stand[$b->kontoId] += $b->betrag;
            }
        }

        return $stand;
    }

    /**
     * Balance per account at the start and end of $zeitraum with the
     * movements in between (Kassenprüfung), narrowed to the reader's period
     * scope. Bookings before an account's opening date do not count (they
     * are in its opening balance). Cost-center scope: none, as kontostaende().
     *
     * @param list<BankAccount> $konten
     * @param list<Kontobewegung> $bewegungen
     *
     * @return list<Kontosaldo> in the order of $konten
     */
    public function kontosalden(array $konten, array $bewegungen, Zeitraum $zeitraum, Zugriffsbereich $bereich): array
    {
        $zeitraum = $zeitraum->begrenztAuf($bereich);
        if ($bereich->kostenstellen !== null || $zeitraum === null) {
            return [];
        }
        $von = self::ymd($zeitraum->von);
        $bis = self::ymd($zeitraum->bis);

        /** @var array<int, array{0: int, 1: int, 2: int, 3: int}> $werte anfang, eroeffnung, zugaenge, abgaenge */
        $werte = [];
        $eroeffnet = self::eroeffnungen($konten);
        foreach ($konten as $konto) {
            if ($eroeffnet[$konto->id] <= $bis) {
                $werte[$konto->id] = $eroeffnet[$konto->id] < $von ? [$konto->openingBalance, 0, 0, 0] : [0, $konto->openingBalance, 0, 0];
            }
        }
        foreach ($bewegungen as $b) {
            if (!isset($werte[$b->kontoId])) {
                continue;
            }
            $tag = self::ymd($b->datum);
            if ($tag < $eroeffnet[$b->kontoId] || $tag > $bis) {
                continue;
            }
            if ($tag < $von) {
                $werte[$b->kontoId][0] += $b->betrag;
            } elseif ($b->betrag >= 0) {
                $werte[$b->kontoId][2] += $b->betrag;
            } else {
                $werte[$b->kontoId][3] -= $b->betrag;
            }
        }

        $salden = [];
        foreach ($konten as $konto) {
            if (isset($werte[$konto->id])) {
                [$anfang, $eroeffnung, $zu, $ab] = $werte[$konto->id];
                $salden[] = new Kontosaldo($konto->id, $anfang, $eroeffnung, $zu, $ab, $anfang + $eroeffnung + $zu - $ab);
            }
        }

        return $salden;
    }

    /**
     * The lines of the input inside its period, the reader's scope and (if
     * given) one side.
     *
     * @return list<Posten>
     */
    private function relevant(Auswertungsgrundlage $grundlage, Zugriffsbereich $bereich, ?InvoiceDirection $nur): array
    {
        return array_values(array_filter(
            $grundlage->posten,
            static fn(Posten $p): bool => ($nur === null || $p->art === $nur)
                && $grundlage->zeitraum->enthaelt($p->datum)
                && $bereich->erlaubt($p->kostenstelleId, $p->datum),
        ));
    }

    private function pruefeDimension(Dimension $dimension): void
    {
        if ($dimension === Dimension::Sphaere && !$this->sphaerenAktiv) {
            throw new \DomainException('Steuerliche Sphären sind ausgeschaltet (sphaeren_aktiv).');
        }
    }

    private static function vergleicheGruppen(Gruppe $a, Gruppe $b): int
    {
        return [$a->schluessel === null, -$a->summen->umfang(), $a->schluessel]
            <=> [$b->schluessel === null, -$b->summen->umfang(), $b->schluessel];
    }

    /** Array key that keeps null, ints and strings apart. */
    private static function index(int|string|null $schluessel): string
    {
        return match (true) {
            $schluessel === null => 'n',
            is_int($schluessel) => 'i' . $schluessel,
            default => 's' . $schluessel,
        };
    }

    private static function monatPlusJahr(string $monat): string
    {
        return sprintf('%04d%s', (int) substr($monat, 0, 4) + 1, substr($monat, 4));
    }

    private static function ymd(\DateTimeImmutable $datum): string
    {
        return $datum->format('Y-m-d');
    }

    /**
     * @param list<BankAccount> $konten
     *
     * @return array<int, string> account id => opening date `Y-m-d`
     */
    private static function eroeffnungen(array $konten): array
    {
        $tage = [];
        foreach ($konten as $konto) {
            $tage[$konto->id] = self::ymd($konto->openingDate);
        }

        return $tage;
    }
}
