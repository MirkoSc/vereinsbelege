<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What the ZIP export (issue #76, docs/spec/05-auswertung-und-export.md
 * section 2) says about a receipt's payment: filled from its allocations
 * once it is settled (M10-1, issue #65). Decrypted values - lives only in
 * the request that builds the export.
 */
final readonly class Zahlungsangaben
{
    /**
     * @param list<string> $konten the names of the accounts the settling bookings lie on, in allocation order
     * @param list<string> $referenzen the purposes of the settling bookings, empty ones left out
     * @param bool $kasse paid in cash - `{kasse}` of the path pattern
     */
    public function __construct(
        public ?\DateTimeImmutable $bezahltAm = null,
        public array $konten = [],
        public array $referenzen = [],
        public bool $kasse = false,
    ) {
    }
}
