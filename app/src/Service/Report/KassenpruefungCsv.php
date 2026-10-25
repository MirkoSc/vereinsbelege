<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Service\Processing\Betrag;

/**
 * The tables of the Kassenprüfung as CSV downloads (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1: "als druckbare Seite + CSV").
 * The same rows the page shows; amounts as integer cents (Berichtstabelle
 * formats them), each with its currency.
 */
final class KassenpruefungCsv
{
    /** Part => label; the part is the last path segment of the download (`…/buchungen.csv`). */
    public const array TEILE = [
        'buchungen' => 'Alle Buchungen',
        'salden' => 'Salden je Konto',
        'ohne-beleg' => 'Buchungen ohne Beleg',
        'manuell' => 'Manuell entschiedene Fälle',
    ];

    public const string FALL_ZUORDNUNG = 'Beleg von Hand zugeordnet';

    public const string FALL_OHNE_BELEGPFLICHT = 'von Hand als „kein Beleg nötig“ markiert';

    private function __construct()
    {
    }

    public static function tabelle(KassenpruefungBericht $bericht, string $teil): Berichtstabelle
    {
        return match ($teil) {
            'buchungen' => self::buchungen($bericht),
            'salden' => self::salden($bericht),
            'ohne-beleg' => self::ohneBeleg($bericht),
            'manuell' => self::manuell($bericht),
            default => throw new \InvalidArgumentException('Unbekannter Teil der Kassenprüfung.'),
        };
    }

    /**
     * File name of a download: the part and the period only - no account,
     * no name, nothing of the club's data (CLAUDE.md section 4).
     */
    public static function dateiname(string $teil, Zeitraum $zeitraum): string
    {
        if (!isset(self::TEILE[$teil])) {
            throw new \InvalidArgumentException('Unbekannter Teil der Kassenprüfung.');
        }

        return sprintf('kassenpruefung-%s-%s-%s.csv', $teil, $zeitraum->von->format('Y-m-d'), $zeitraum->bis->format('Y-m-d'));
    }

    private static function buchungen(KassenpruefungBericht $bericht): Berichtstabelle
    {
        $zeilen = [];
        foreach ($bericht->buchungen as $p) {
            $b = $p->buchung;
            $zeilen[] = [
                ...self::buchungsspalten($p),
                $b->source->label(),
                $p->istUmbuchung() ? 'Umbuchung (kein Beleg nötig)' : $b->docStatus->label(),
                $p->zugeordnet(),
                $p->fehltBeleg() ? $p->ohneBeleg() : null,
                implode(' | ', [
                    ...array_map(static fn(Pruefzuordnung $z): string => $z->bezeichnung() . ' ' . Betrag::format($z->zuordnung->amount), $p->zuordnungen),
                    ...($p->zuordnungUnlesbar ? ['Zuordnung nicht lesbar'] : []),
                ]),
                $p->nichtImSaldo === null ? null : 'nicht im Saldo: ' . $p->nichtImSaldo,
            ];
        }

        return new Berichtstabelle(
            ['Datum', 'Konto', 'Gegenseite', 'Verwendungszweck', 'Betrag', 'Währung', 'Erfasst über', 'Belegstatus', 'Zugeordnet', 'Ohne Beleg', 'Belege', 'Hinweis'],
            $zeilen,
        );
    }

    private static function salden(KassenpruefungBericht $bericht): Berichtstabelle
    {
        $zeilen = [];
        foreach ($bericht->salden as $z) {
            $s = $z->saldo;
            $zeilen[] = [$z->konto->data->name, strtoupper($z->konto->currency), $s->anfang, $s->eroeffnung, $s->zugaenge, $s->abgaenge, $s->ende];
        }

        return new Berichtstabelle(
            ['Konto', 'Währung', $bericht->uebertrag ? 'Anfang (Übertrag)' : 'Anfang', 'Eröffnungsbestand im Zeitraum', 'Zugänge', 'Abgänge', 'Ende'],
            $zeilen,
        );
    }

    private static function ohneBeleg(KassenpruefungBericht $bericht): Berichtstabelle
    {
        $zeilen = [];
        foreach ($bericht->ohneBeleg() as $p) {
            $zeilen[] = [...self::buchungsspalten($p), $p->buchung->docStatus->label(), $p->ohneBeleg()];
        }

        return new Berichtstabelle(
            ['Datum', 'Konto', 'Gegenseite', 'Verwendungszweck', 'Betrag', 'Währung', 'Belegstatus', 'Ohne Beleg'],
            $zeilen,
        );
    }

    /** One row per allocation made by hand, one per "kein Beleg nötig" set by a person. */
    private static function manuell(KassenpruefungBericht $bericht): Berichtstabelle
    {
        $zeilen = [];
        foreach ($bericht->manuelleFaelle() as $p) {
            if ($p->vonHandOhneBelegpflicht()) {
                $zeilen[] = [...self::buchungsspalten($p), self::FALL_OHNE_BELEGPFLICHT, null, null, null];
            }
            foreach ($p->zuordnungenVonHand() as $z) {
                $zeilen[] = [
                    ...self::buchungsspalten($p),
                    self::FALL_ZUORDNUNG,
                    $z->beleg?->bezeichnung() ?? 'Beleg außerhalb Ihres Zugriffsbereichs',
                    $z->zuordnung->amount,
                    $z->zuordnung->createdAt->format('d.m.Y'),
                ];
            }
        }

        return new Berichtstabelle(
            ['Datum', 'Konto', 'Gegenseite', 'Verwendungszweck', 'Betrag', 'Währung', 'Fall', 'Beleg', 'Zugeordneter Betrag', 'Zugeordnet am'],
            $zeilen,
        );
    }

    /**
     * @return list<string|int>
     */
    private static function buchungsspalten(Pruefbuchung $p): array
    {
        $b = $p->buchung;

        return [
            $b->bookingDate->format('d.m.Y'),
            $p->konto,
            $b->counterpartyName,
            $b->purpose,
            $b->amount,
            strtoupper($b->currency),
        ];
    }
}
