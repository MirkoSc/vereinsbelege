<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The budget page of one year (issue #92): Soll against Ist per budget,
 * with the plaintext names of categories and cost centers.
 */
final readonly class Budgetergebnis
{
    /**
     * @param Zeitraum|null $sichtbar the part of the year the reader may see;
     *        null = none (period scope), then $zeilen is empty
     * @param list<Budgetzeile> $zeilen sorted by category, then cost center
     * @param array<int, string> $kategorien names by id
     * @param array<int, string> $kostenstellen names by id
     * @param int $budgetsUnlesbar budgets that could not be decrypted
     * @param int $unlesbar report rows that could not be decrypted
     * @param int $fremdwaehrung report rows in another currency than EUR
     */
    public function __construct(
        public int $jahr,
        public ?Zeitraum $sichtbar,
        public array $zeilen,
        public array $kategorien = [],
        public array $kostenstellen = [],
        public int $budgetsUnlesbar = 0,
        public int $unlesbar = 0,
        public int $fremdwaehrung = 0,
    ) {
    }

    /** Whether the reader sees only part of the year (period scope). */
    public function teiljahr(): bool
    {
        if ($this->sichtbar === null) {
            return false;
        }
        $jahr = Zeitraum::jahr($this->jahr);

        return $this->sichtbar->von->format('Y-m-d') !== $jahr->von->format('Y-m-d')
            || $this->sichtbar->bis->format('Y-m-d') !== $jahr->bis->format('Y-m-d');
    }

    public function kategorie(Budgetzeile $zeile): string
    {
        $id = $zeile->soll->kategorieId;

        return $id === null ? 'alle Kategorien' : ($this->kategorien[$id] ?? sprintf('Kategorie Nr. %d', $id));
    }

    public function kostenstelle(Budgetzeile $zeile): string
    {
        $id = $zeile->soll->kostenstelleId;

        return $id === null ? 'alle Kostenstellen' : ($this->kostenstellen[$id] ?? sprintf('Kostenstelle Nr. %d', $id));
    }
}
