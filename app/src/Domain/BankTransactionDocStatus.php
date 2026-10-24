<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a booking has its receipt (`bank_transaction.doc_status`, M9-4,
 * issue #62, docs/spec/02-datenmodell.md "Fachdaten"). The values are a
 * storage format. `fehlt` and `nicht_noetig` are what import, rules and
 * the person decide (M9-6); `teilweise` and `zugeordnet` are DERIVED from
 * the allocations by App\Service\Bank\Zuordnung (M10-1, issue #65) - the
 * only place that writes them, through ableiten().
 */
enum BankTransactionDocStatus: string
{
    case Fehlt = 'fehlt';

    /** Allocations exist but fall short of the booking's amount - a collective transfer with an invoice missing. */
    case Teilweise = 'teilweise';

    case Zugeordnet = 'zugeordnet';
    case NichtNoetig = 'nicht_noetig';

    public static function fuerNeueBuchung(bool $belegNoetig): self
    {
        return $belegNoetig ? self::Fehlt : self::NichtNoetig;
    }

    /**
     * The status the allocations imply (M10-1): without any, what the
     * booking's own choice says; otherwise whether their sum reaches the
     * booking's amount.
     *
     * @param int $betrag the booking's amount in cents, sign ignored
     * @param int $zugeordnet the sum of the allocations in cents
     */
    public static function ableiten(bool $belegNoetig, int $betrag, int $zugeordnet): self
    {
        if ($zugeordnet <= 0) {
            return self::fuerNeueBuchung($belegNoetig);
        }

        return $zugeordnet >= abs($betrag) ? self::Zugeordnet : self::Teilweise;
    }

    /**
     * A receipt is linked - fully or in part. Such a booking is not deleted,
     * and neither a rule nor the person's choice of "Beleg nötig" changes
     * this status; the allocations do (M10-1).
     */
    public function hatBeleg(): bool
    {
        return $this === self::Zugeordnet || $this === self::Teilweise;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fehlt => 'Beleg fehlt',
            self::Teilweise => 'Beleg teilweise zugeordnet',
            self::Zugeordnet => 'Beleg zugeordnet',
            self::NichtNoetig => 'kein Beleg nötig',
        };
    }
}
