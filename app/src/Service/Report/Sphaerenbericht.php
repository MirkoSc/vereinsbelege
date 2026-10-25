<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\Sphere;
use App\Domain\Zugriffsbereich;
use App\Repository\CategoryRepository;
use App\Service\Crypto\Vault;

/**
 * Income, expenses and balance per tax sphere (issue #84, E-57, docs/spec/
 * 05-auswertung-und-export.md section 1). Only with `sphaeren_aktiv` on -
 * the ReportService refuses the sphere dimension otherwise (E-15).
 *
 * A line's sphere is its receipt's own (`invoice.sphere`, chosen on the
 * review page) or, if the receipt has none, the default of its category
 * (`category.default_sphere`); a booking part without a receipt has only
 * its category's default. The defaults are read on every call, never from
 * the session cache (E-48): the cache keeps the stored receipt sphere only,
 * so changing a category's default or the switch shows on the next page
 * view without a stale figure.
 *
 * Every sphere is listed, also with zero, and the lines without any sphere
 * last - what is still to be assigned. Pure figures; which sphere is right
 * is for the club and its tax adviser (the page says so).
 */
final readonly class Sphaerenbericht
{
    public function __construct(
        private Auswertungsquelle $quelle,
        private CategoryRepository $kategorien,
    ) {
    }

    public function bericht(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): SphaerenErgebnis
    {
        $rechner = $this->quelle->rechner();
        $sichtbar = $zeitraum->begrenztAuf($bereich);
        if ($sichtbar === null) {
            // Asked anyway, so a switched-off installation fails here as well.
            $rechner->gruppiert(new Auswertungsgrundlage($grundlage, $zeitraum, []), $bereich, Dimension::Sphaere);

            return new SphaerenErgebnis(null, [], new Summen());
        }

        $daten = $this->quelle->grundlage($vault, $grundlage, $zeitraum, $bereich);
        [$posten, $ausKategorie] = $this->mitVorgaben($daten->posten);
        $gruppen = $rechner->gruppiert(
            new Auswertungsgrundlage($daten->grundlage, $daten->zeitraum, $posten, $daten->unlesbar, $daten->fremdwaehrung),
            $bereich,
            Dimension::Sphaere,
        );

        $summen = [];
        foreach ($gruppen as $gruppe) {
            $summen[is_string($gruppe->schluessel) ? $gruppe->schluessel : ''] = $gruppe->summen;
        }
        $zeilen = [];
        $gesamt = new Summen();
        foreach ([...Sphere::cases(), null] as $sphaere) {
            $s = $summen[$sphaere?->value ?? ''] ?? new Summen();
            if ($sphaere === null && $s->anzahl === 0) {
                continue;
            }
            $zeilen[] = new SphaerenZeile($sphaere, $s, $ausKategorie[$sphaere?->value ?? ''] ?? 0);
            $gesamt = $gesamt->plus($s);
        }

        return new SphaerenErgebnis($sichtbar, $zeilen, $gesamt, $daten->unlesbar, $daten->fremdwaehrung);
    }

    /**
     * The lines with their effective sphere: their own if it is a known
     * sphere, else their category's default, else none.
     *
     * @param list<Posten> $posten
     *
     * @return array{0: list<Posten>, 1: array<string, int>} the lines, and per
     *         sphere how many took it from their category
     */
    private function mitVorgaben(array $posten): array
    {
        $vorgaben = [];
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->defaultSphere !== null) {
                $vorgaben[$kategorie->id] = $kategorie->defaultSphere;
            }
        }

        $ergebnis = [];
        $ausKategorie = [];
        foreach ($posten as $p) {
            $eigene = Sphere::ausWert($p->sphaere);
            $wirksam = $eigene ?? ($p->kategorieId === null ? null : ($vorgaben[$p->kategorieId] ?? null));
            if ($eigene === null && $wirksam !== null) {
                $ausKategorie[$wirksam->value] = ($ausKategorie[$wirksam->value] ?? 0) + 1;
            }
            $ergebnis[] = $wirksam?->value === $p->sphaere ? $p : new Posten(
                $p->datum,
                $p->art,
                $p->betrag,
                $p->kategorieId,
                $p->kostenstelleId,
                $p->lieferantId,
                $wirksam?->value,
                $p->kontoId,
                $p->belegId,
                $p->buchungId,
            );
        }

        return [$ergebnis, $ausKategorie];
    }
}
