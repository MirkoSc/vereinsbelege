<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The `document.status` state machine (docs/spec/02-datenmodell.md
 * "Statusmodell document.status"):
 *
 *   eingegangen -> bereit_zur_auswertung -> ausgewertet -> in_pruefung
 *              -> geprueft -> festgeschrieben
 *   eingegangen/ausgewertet/ki_fehler -> wiedervorlage -> (zurück)
 *   bereit_zur_auswertung/ausgewertet -> ki_fehler -> (neuer Versuch)
 *   eingegangen/wiedervorlage/in_pruefung -> abgelehnt (mit Grund, bleibt
 *   erhalten)
 *
 * A freshly received document (issue #24/M4-2, either from the public
 * submission or, from M4-6 on, internal capture) starts at `Eingegangen`.
 * uebergaenge() is the one table every status change is checked against
 * (issue #27/M4-5): the inbox (App\Domain\InboxAction) as well as the AI
 * pipeline later (M7). `Festgeschrieben` and `Abgelehnt` are final.
 */
enum DocumentStatus: string
{
    case Eingegangen = 'eingegangen';
    case BereitZurAuswertung = 'bereit_zur_auswertung';
    case Ausgewertet = 'ausgewertet';
    case InPruefung = 'in_pruefung';
    case Geprueft = 'geprueft';
    case Festgeschrieben = 'festgeschrieben';
    case KiFehler = 'ki_fehler';
    case Wiedervorlage = 'wiedervorlage';
    case Abgelehnt = 'abgelehnt';

    /**
     * Every status this one may change to.
     *
     * @return list<self>
     */
    public function uebergaenge(): array
    {
        return match ($this) {
            self::Eingegangen => [self::BereitZurAuswertung, self::Wiedervorlage, self::Abgelehnt],
            self::BereitZurAuswertung => [self::Ausgewertet, self::KiFehler],
            self::Ausgewertet => [self::InPruefung, self::KiFehler, self::Wiedervorlage],
            self::InPruefung => [self::Geprueft, self::Abgelehnt],
            self::Geprueft => [self::Festgeschrieben],
            self::KiFehler => [self::BereitZurAuswertung, self::Wiedervorlage],
            self::Wiedervorlage => [self::BereitZurAuswertung, self::Abgelehnt],
            self::Festgeschrieben, self::Abgelehnt => [],
        };
    }

    public function kannWechselnZu(self $ziel): bool
    {
        return in_array($ziel, $this->uebergaenge(), true);
    }

    public function istEndzustand(): bool
    {
        return $this->uebergaenge() === [];
    }

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Eingegangen => 'Eingegangen',
            self::BereitZurAuswertung => 'Bereit zur Auswertung',
            self::Ausgewertet => 'Ausgewertet',
            self::InPruefung => 'In Prüfung',
            self::Geprueft => 'Geprüft',
            self::Festgeschrieben => 'Festgeschrieben',
            self::KiFehler => 'KI fehlgeschlagen',
            self::Wiedervorlage => 'Wiedervorlage',
            self::Abgelehnt => 'Abgelehnt',
        };
    }
}
