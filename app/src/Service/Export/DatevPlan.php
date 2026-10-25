<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * What a DATEV export of a period would hold (issue #93, E-49), decrypted
 * in the session: the rows, and every booking that cannot be written yet
 * and why. App\App\DatevController shows it as the preview and only offers
 * the download when nothing is missing - a batch with a booking silently
 * left out would be wrong at the advisor's.
 */
final readonly class DatevPlan
{
    /**
     * @param list<DatevZeile> $zeilen
     * @param array<int, int> $ohneFinanzkonto account id => bookings whose account (or transfer partner) has no ledger account
     * @param array<int, int> $ohneGegenkonto category id (0 = no category) => bookings without a contra account
     * @param int $fremdwaehrung bookings not in EUR - not exported
     * @param int $nullbetraege bookings of 0,00 - DATEV refuses them, left out
     * @param int $gegenbuchungen incoming halves of transfers left out - the outgoing half is the row (no money-in-transit account)
     * @param array<int, string> $kontoNamen account id => name, for the page
     * @param array<int, string> $kategorieNamen category id => name, for the page
     */
    public function __construct(
        public DatevZeitraum $zeitraum,
        public ?DatevKopf $kopf,
        public array $zeilen,
        public array $ohneFinanzkonto = [],
        public array $ohneGegenkonto = [],
        public int $fremdwaehrung = 0,
        public int $nullbetraege = 0,
        public int $gegenbuchungen = 0,
        public array $kontoNamen = [],
        public array $kategorieNamen = [],
    ) {
    }

    /** Bookings that keep the export back. */
    public function fehlend(): int
    {
        return array_sum($this->ohneFinanzkonto) + array_sum($this->ohneGegenkonto) + $this->fremdwaehrung;
    }

    public function zuGross(): bool
    {
        return count($this->zeilen) > DatevBuchungsstapel::MAX_ZEILEN;
    }

    public function istExportierbar(): bool
    {
        return $this->kopf !== null && $this->zeilen !== [] && $this->fehlend() === 0 && !$this->zuGross();
    }

    public function datei(): string
    {
        if (!$this->istExportierbar() || $this->kopf === null) {
            throw new \LogicException('This DATEV export is not complete.');
        }

        return DatevBuchungsstapel::datei($this->kopf, $this->zeilen);
    }
}
