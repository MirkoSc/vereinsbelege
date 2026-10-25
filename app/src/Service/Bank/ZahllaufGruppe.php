<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One payee on the "new payment run" page (issue #85, E-42): a group of
 * App\Service\Bank\Uebersichten::erstattungen() (name AND IBAN from the
 * submission) with the name as the file will carry it. A group with a
 * $sperrgrund (the same name with another IBAN, no IBAN, an own account,
 * ...) offers none of its receipts - those are paid by hand after a check.
 */
final readonly class ZahllaufGruppe
{
    /**
     * @param list<ZahllaufKandidat> $kandidaten
     */
    public function __construct(
        public string $name,
        public string $sepaName,
        public string $iban,
        public ?string $sperrgrund,
        public array $kandidaten,
    ) {
    }

    /** Cents of the receipts that can go into a run. */
    public function waehlbareSumme(): int
    {
        $summe = 0;
        foreach ($this->kandidaten as $k) {
            if ($k->waehlbar()) {
                $summe += $k->posten->offen;
            }
        }

        return $summe;
    }

    public function hatWaehlbare(): bool
    {
        foreach ($this->kandidaten as $k) {
            if ($k->waehlbar()) {
                return true;
            }
        }

        return false;
    }
}
