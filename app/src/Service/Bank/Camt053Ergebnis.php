<?php

declare(strict_types=1);

namespace App\Service\Bank;

/**
 * What App\Service\Bank\Camt053Parser read from a file: the statements and
 * how many entries it skipped because the bank had not booked them yet
 * (status other than BOOK).
 */
final readonly class Camt053Ergebnis
{
    /**
     * @param list<Kontoauszug> $auszuege in chronological order
     */
    public function __construct(
        public array $auszuege,
        public int $vorgemerkt,
    ) {
    }
}
