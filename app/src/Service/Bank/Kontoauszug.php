<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * One statement message of an MT940 file (:20: up to the closing "-").
 */
final readonly class Kontoauszug
{
    /**
     * @param list<Umsatz> $umsaetze
     */
    public function __construct(
        public string $referenz,
        public Kontoangabe $konto,
        public ?string $auszugsnummer,
        public Saldo $anfangssaldo,
        public Saldo $schlusssaldo,
        public array $umsaetze,
    ) {
    }

    public function summeUmsaetzeCent(): int
    {
        return array_sum(array_map(static fn (Umsatz $u): int => $u->cent, $this->umsaetze));
    }

    /**
     * Balance check (spec 04 section 2): opening + sum of transactions -
     * closing. 0 when the statement is consistent.
     */
    public function saldoDifferenzCent(): int
    {
        return $this->anfangssaldo->cent + $this->summeUmsaetzeCent() - $this->schlusssaldo->cent;
    }

    /**
     * False is an import warning, not an error - the file is still read.
     */
    public function saldoStimmt(): bool
    {
        return $this->saldoDifferenzCent() === 0
            && $this->anfangssaldo->waehrung === $this->schlusssaldo->waehrung;
    }
}
