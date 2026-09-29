<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which side of the books a supplier usually appears on (`supplier.role`,
 * M6-2, issue #36, docs/spec/02-datenmodell.md "Lieferanten"): a supplier
 * sends invoices, a payer (sponsor, municipality, member) pays the club.
 * Plaintext, so the list and later the review page (M6-3) filter in SQL.
 */
enum SupplierRole: string
{
    case Lieferant = 'lieferant';
    case Zahler = 'zahler';
    case Beide = 'beide';

    public function label(): string
    {
        return match ($this) {
            self::Lieferant => 'Lieferant',
            self::Zahler => 'Zahler',
            self::Beide => 'Lieferant und Zahler',
        };
    }

    /**
     * The direction of the categories that fit as a default: a supplier's
     * receipts are expenses, a payer's income. Null means any.
     */
    public function kategorieRichtung(): ?CategoryDirection
    {
        return match ($this) {
            self::Lieferant => CategoryDirection::Ausgabe,
            self::Zahler => CategoryDirection::Einnahme,
            self::Beide => null,
        };
    }

    public function passtZu(CategoryDirection $richtung): bool
    {
        $eigene = $this->kategorieRichtung();

        return $eigene === null || $richtung === CategoryDirection::Beide || $richtung === $eigene;
    }
}
