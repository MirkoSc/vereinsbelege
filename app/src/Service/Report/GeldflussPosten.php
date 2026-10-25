<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * One line of the drilldown (issue #73): a receipt, or the part of a
 * booking, with what a human recognises it by. Decrypted - only for the
 * rendered page, never logged, never in a URL (only the ids are).
 */
final readonly class GeldflussPosten
{
    public function __construct(
        public \DateTimeImmutable $datum,
        /** Integer cents on the chosen side, negative for a credit note. */
        public int $betrag,
        /** Supplier name, booking counterparty, or a placeholder. */
        public string $bezeichnung,
        /** Invoice number and short purpose, or the booking's purpose. */
        public string $detail,
        public string $kategorie,
        public string $kostenstelle,
        /** The document of the receipt (what receipt pages are addressed by), null without a receipt. */
        public ?int $dokumentId,
        /** The booking, null on the receipt basis. */
        public ?int $buchungId,
    ) {
    }
}
