<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The `document.status` state machine (docs/spec/02-datenmodell.md
 * "Statusmodell document.status"):
 *
 *   eingegangen -> bereit_zur_auswertung -> ausgewertet -> in_pruefung
 *              -> geprueft -> festgeschrieben
 *   ausgewertet/in_pruefung -> ki_fehler | wiedervorlage -> (zurück)
 *   -> abgelehnt (mit Grund, bleibt erhalten)
 *
 * A freshly received document (issue #24/M4-2, either from the public
 * submission or, from M4-6 on, internal capture) starts at `Eingegangen`.
 * Every later case is transitioned to by the inbox (M4-5) and the AI
 * pipeline (M7) - this enum only defines the vocabulary, not the rules.
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
}
