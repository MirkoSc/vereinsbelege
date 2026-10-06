<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\BalanceCheck;

/**
 * What App\Service\Bank\Import\Saldenpruefung found (M9-4, issue #62).
 *
 * `punkte` checks the file against itself (MT940 statements, the gaps
 * between them, the balance column of a CSV export); `anschluss` the file
 * against the balance the application knows, null when that could not be
 * checked (see Saldenpruefung::pruefe()).
 */
final readonly class SaldenErgebnis
{
    /**
     * @param list<Saldenpruefpunkt> $punkte
     */
    public function __construct(
        public BalanceCheck $ergebnis,
        public array $punkte,
        public ?Saldenpruefpunkt $anschluss,
    ) {
    }

    /**
     * @return list<Saldenpruefpunkt>
     */
    public function abweichungen(): array
    {
        $alle = $this->anschluss === null ? $this->punkte : [...$this->punkte, $this->anschluss];

        return array_values(array_filter($alle, static fn(Saldenpruefpunkt $p): bool => !$p->stimmt()));
    }
}
