<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * Everything the dashboard shows (issue #72, docs/spec/
 * 05-auswertung-und-export.md section 1 "Dashboard"), already narrowed to
 * the reader's rights and scopes. A block the account may not see is not
 * computed at all (its `erlaubt` flag is false); a block it may see but
 * that needs the vault stays null while the vault is locked.
 */
final readonly class DashboardKennzahlen
{
    /**
     * @param list<Kontostand>|null $kontostaende
     * @param list<Zaehlwert> $zaehler
     */
    public function __construct(
        public int $jahr,
        /** The part of the year the reader's period scope allows; null = none of it. */
        public ?Zeitraum $sichtbar,
        /** `report.view`: income, expenses and balance of the year. */
        public bool $summenErlaubt,
        public ?Summen $summen,
        public int $unlesbar,
        public int $fremdwaehrung,
        /** `bank.view` without a cost-center scope: balances per account. */
        public bool $kontenErlaubt,
        public ?array $kontostaende,
        /** The day the balances are at: today in the current year, else 31 December (within the period scope). */
        public ?\DateTimeImmutable $stichtag,
        public int $kontenUnlesbar,
        public array $zaehler,
    ) {
    }

    /** Sum of the balances when all accounts are in EUR, else null. */
    public function kontenSumme(): ?int
    {
        if ($this->kontostaende === null || $this->kontostaende === []) {
            return null;
        }
        $summe = 0;
        foreach ($this->kontostaende as $k) {
            if (strtoupper($k->konto->currency) !== 'EUR') {
                return null;
            }
            $summe += $k->stand;
        }

        return $summe;
    }
}
