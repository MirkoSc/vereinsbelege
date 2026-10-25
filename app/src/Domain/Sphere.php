<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The tax sphere of a non-profit club (docs/spec/02-datenmodell.md
 * "Sphären", issue #84, E-57): stored in `invoice.sphere` and
 * `category.default_sphere` as the value. Only used while the setting
 * `sphaeren_aktiv` is on (E-15) - App\Service\MasterData\
 * Sphaereneinstellung. The software gives the structure only; which
 * sphere is right is for the club and its tax adviser to decide.
 */
enum Sphere: string
{
    case Ideell = 'ideell';
    case Vermoegensverwaltung = 'vermoegensverwaltung';
    case Zweckbetrieb = 'zweckbetrieb';
    case Wirtschaftlich = 'wirtschaftlich';

    public function label(): string
    {
        return match ($this) {
            self::Ideell => 'Ideeller Bereich',
            self::Vermoegensverwaltung => 'Vermögensverwaltung',
            self::Zweckbetrieb => 'Zweckbetrieb',
            self::Wirtschaftlich => 'Wirtschaftlicher Geschäftsbetrieb',
        };
    }

    /** Typical examples, as a hint next to the choice - no tax advice. */
    public function beispiele(): string
    {
        return match ($this) {
            self::Ideell => 'z. B. Mitgliedsbeiträge, Spenden, Zuschüsse, Kosten des Sportbetriebs',
            self::Vermoegensverwaltung => 'z. B. Zinsen, langfristige Vermietung, Werbung ohne eigene Mitwirkung',
            self::Zweckbetrieb => 'z. B. Eintrittsgelder für Sportveranstaltungen (in Grenzen)',
            self::Wirtschaftlich => 'z. B. Verkauf von Speisen und Getränken, Sponsoring mit Gegenleistung',
        };
    }

    /** null for null, '' and anything unknown - a stored value never breaks a page. */
    public static function ausWert(?string $wert): ?self
    {
        return $wert === null || $wert === '' ? null : self::tryFrom($wert);
    }
}
