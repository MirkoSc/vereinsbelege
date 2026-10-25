<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The balance of one account over a period, in cents (Kassenprüfung,
 * docs/spec/05-auswertung-und-export.md section 1: "Salden je Konto am
 * Anfang/Ende"). Always anfang + eroeffnung + zugaenge - abgaenge = ende.
 */
final readonly class Kontosaldo
{
    public function __construct(
        public int $kontoId,
        /** Balance at the end of the day before the period; 0 if the account was opened later. */
        public int $anfang,
        /** The opening balance when the account was opened within the period, else 0. */
        public int $eroeffnung,
        /** Positive bookings in the period, transfers included. */
        public int $zugaenge,
        /** Negative bookings in the period as a positive number, transfers included. */
        public int $abgaenge,
        /** Balance at the end of the period's last day. */
        public int $ende,
    ) {
    }
}
