<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which side of the books a receipt is on (`invoice.direction`, issue
 * #37/M6-3, docs/spec/02-datenmodell.md "Fachdaten"): an expense the club
 * pays, or income it receives. Decides which categories and which partners
 * the review page offers - a supplier's invoice is an expense, a payer's
 * receipt income (App\Domain\SupplierRole).
 */
enum InvoiceDirection: string
{
    case Ausgabe = 'ausgabe';
    case Einnahme = 'einnahme';

    public function label(): string
    {
        return match ($this) {
            self::Ausgabe => 'Ausgabe',
            self::Einnahme => 'Einnahme',
        };
    }

    /** What the partner of such a receipt is called on the page. */
    public function partner(): string
    {
        return match ($this) {
            self::Ausgabe => 'Lieferant',
            self::Einnahme => 'Zahler',
        };
    }

    public function kategorieRichtung(): CategoryDirection
    {
        return match ($this) {
            self::Ausgabe => CategoryDirection::Ausgabe,
            self::Einnahme => CategoryDirection::Einnahme,
        };
    }

    public function passtZuKategorie(CategoryDirection $richtung): bool
    {
        return $richtung === CategoryDirection::Beide || $richtung === $this->kategorieRichtung();
    }

    /**
     * The roles of the partners that fit - `beide` fits either way.
     *
     * @return list<SupplierRole>
     */
    public function lieferantenRollen(): array
    {
        return [$this->neueLieferantenRolle(), SupplierRole::Beide];
    }

    public function passtZuLieferant(SupplierRole $rolle): bool
    {
        return in_array($rolle, $this->lieferantenRollen(), true);
    }

    /** The role a partner created right from the review page gets. */
    public function neueLieferantenRolle(): SupplierRole
    {
        return match ($this) {
            self::Ausgabe => SupplierRole::Lieferant,
            self::Einnahme => SupplierRole::Zahler,
        };
    }
}
