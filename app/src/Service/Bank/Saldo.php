<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * An opening or closing balance (:60F:/:60M:, :62F:/:62M:).
 */
final readonly class Saldo
{
    /**
     * @param int  $cent           signed: negative for a debit balance
     * @param bool $zwischensaldo  :60M:/:62M: - the statement continues in
     *                             the next message
     */
    public function __construct(
        public \DateTimeImmutable $datum,
        public int $cent,
        public string $waehrung,
        public bool $zwischensaldo,
    ) {
    }
}
