<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * The score of one receipt/booking pair and the signals behind it (M10-2,
 * issue #66). `trace()` is what `allocation.rule_trace` stores: signal keys
 * and points, never a value of either side.
 */
final readonly class Bewertung
{
    /**
     * @param array<string, int> $signale Signal value => points, in the order they fired
     */
    public function __construct(
        public int $invoiceId,
        public int $transactionId,
        public int $score,
        public array $signale,
        /** The amount an allocation would take: min(open of the receipt, open of the booking). */
        public int $betrag,
        /** Both sides are settled completely by this pair - open amounts equal. */
        public bool $vollGedeckt,
    ) {
    }

    /**
     * @return array{v: int, signale: array<string, int>}
     */
    public function trace(): array
    {
        return ['v' => 1, 'signale' => $this->signale];
    }

    /**
     * @return list<array{label: string, punkte: int}>
     */
    public function begruendung(): array
    {
        $liste = [];
        foreach ($this->signale as $signal => $punkte) {
            $liste[] = ['label' => Signal::tryFrom($signal)?->label() ?? $signal, 'punkte' => $punkte];
        }

        return $liste;
    }
}
