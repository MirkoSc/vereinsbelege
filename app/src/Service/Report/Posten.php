<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\InvoiceDirection;

/**
 * One report line, decrypted (E-44): the unit every report figure sums up.
 * On the receipt basis one line per checked receipt; on the booking basis
 * one line per booking - split into one line per allocated receipt (which
 * brings its category, cost center, supplier, sphere) plus the unallocated
 * rest (the booking's category, no cost center). Built by Postenbildung in a
 * session with an unlocked vault - never logged, never put into a URL.
 */
final readonly class Posten
{
    /**
     * @param int $betrag integer cents on the side $art: positive normally,
     *        negative when it reduces that side (credit note, refund)
     */
    public function __construct(
        public \DateTimeImmutable $datum,
        public InvoiceDirection $art,
        public int $betrag,
        public ?int $kategorieId = null,
        public ?int $kostenstelleId = null,
        public ?int $lieferantId = null,
        public ?string $sphaere = null,
        public ?int $kontoId = null,
        /** The invoice id (not the document id), null for an unallocated booking part. */
        public ?int $belegId = null,
        /** The booking id, null on the receipt basis. */
        public ?int $buchungId = null,
    ) {
    }

    /** What the line adds to the balance: income plus, expense minus. */
    public function saldo(): int
    {
        return $this->art === InvoiceDirection::Einnahme ? $this->betrag : -$this->betrag;
    }
}
