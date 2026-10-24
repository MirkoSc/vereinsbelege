<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The payment status of a receipt (`invoice.payment_status`, M10-1, issue
 * #65, docs/spec/04-bank-und-abgleich.md section 5 "Modell"). DERIVED: the
 * only way to a value is ableiten() from the allocations' sum, the gross
 * amount, the Zahlungsweg and a closed difference - App\Service\Bank\
 * Zuordnung stores the result so SQL can filter on it; nobody sets it by
 * hand. The values are a storage format.
 */
enum PaymentStatus: string
{
    case Offen = 'offen';
    case Teilbezahlt = 'teilbezahlt';
    case Bezahlt = 'bezahlt';
    case ErstattungOffen = 'erstattung_offen';
    case Erstattet = 'erstattet';
    case KeinZahlungsbezug = 'kein_zahlungsbezug';

    /**
     * @param int $brutto the receipt's gross amount in cents
     * @param int $zugeordnet the sum of its allocations in cents
     * @param Differenzgrund|null $differenz why a shortfall counts as settled
     */
    public static function ableiten(Zahlungsweg $weg, int $brutto, int $zugeordnet, ?Differenzgrund $differenz): self
    {
        if (!$weg->erwartetBuchung()) {
            return $weg === Zahlungsweg::Bar ? self::Bezahlt : self::KeinZahlungsbezug;
        }
        $erstattung = $weg === Zahlungsweg::Erstattung;
        if ($zugeordnet <= 0) {
            return $erstattung ? self::ErstattungOffen : self::Offen;
        }
        if ($zugeordnet >= $brutto || $differenz !== null) {
            return $erstattung ? self::Erstattet : self::Bezahlt;
        }

        return self::Teilbezahlt;
    }

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'offen',
            self::Teilbezahlt => 'teilweise bezahlt',
            self::Bezahlt => 'bezahlt',
            self::ErstattungOffen => 'Erstattung offen',
            self::Erstattet => 'erstattet',
            self::KeinZahlungsbezug => 'kein Zahlungsbezug',
        };
    }

    /** Money is still expected to move - what the unpaid lists (M10-5) and the matching (M10-2/3) look at. */
    public function offen(): bool
    {
        return match ($this) {
            self::Offen, self::Teilbezahlt, self::ErstattungOffen => true,
            self::Bezahlt, self::Erstattet, self::KeinZahlungsbezug => false,
        };
    }

    /** Settled by bookings (or a closed difference): "ausgeglichen". */
    public function ausgeglichen(): bool
    {
        return $this === self::Bezahlt || $this === self::Erstattet;
    }
}
