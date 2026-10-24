<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One reason why a booking may belong to a receipt (M10-2, issue #66,
 * docs/spec/04-bank-und-abgleich.md section 5 "Punktebewertung"). The value
 * is what `allocation.rule_trace` stores - never the matched value itself.
 */
enum Signal: string
{
    case BetragExakt = 'betrag_exakt';
    case BetragSkonto = 'betrag_skonto';
    case Rechnungsnummer = 'rechnungsnummer';
    case Iban = 'iban';
    case Mandat = 'mandat';
    case Name = 'name';
    case Kundennummer = 'kundennummer';
    case DatumImFenster = 'datum_im_fenster';
    case DatumAusserhalb = 'datum_ausserhalb';
    case Erstattung = 'erstattung';

    public function label(): string
    {
        return match ($this) {
            self::BetragExakt => 'Betrag stimmt genau',
            self::BetragSkonto => 'Betrag innerhalb der Skonto-Toleranz',
            self::Rechnungsnummer => 'Rechnungsnummer im Verwendungszweck',
            self::Iban => 'IBAN der Gegenseite ist beim Lieferanten bekannt',
            self::Mandat => 'Mandatsreferenz oder Gläubiger-ID ist beim Lieferanten bekannt',
            self::Name => 'Name der Gegenseite ähnelt dem Lieferanten',
            self::Kundennummer => 'Kundennummer im Verwendungszweck',
            self::DatumImFenster => 'Buchungsdatum passt zu Rechnungsdatum/Fälligkeit',
            self::DatumAusserhalb => 'Buchungsdatum liegt außerhalb des erwarteten Zeitraums',
            self::Erstattung => 'Erstattung: IBAN des Einreichers und Belegsumme stimmen',
        };
    }
}
