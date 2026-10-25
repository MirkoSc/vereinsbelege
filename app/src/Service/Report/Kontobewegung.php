<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * One booking as far as a balance needs it: account, date, signed amount in
 * cents. Transfers between own accounts are included (E-38) - they move a
 * balance even though they are neither income nor expense.
 */
final readonly class Kontobewegung
{
    public function __construct(
        public int $kontoId,
        public \DateTimeImmutable $datum,
        public int $betrag,
    ) {
    }
}
