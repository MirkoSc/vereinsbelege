<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * One booking of a DATEV Buchungsstapel (issue #93, E-49), decrypted -
 * built in a session with the unlocked vault, never logged or stored.
 *
 * The amount is seen from "Konto" (the bank account or cash box): money in
 * is positive and written as Soll, money out negative and written as Haben;
 * "Gegenkonto" takes the other side.
 */
final readonly class DatevZeile
{
    /**
     * @param int $betragCent integer cents, signed as above, never 0
     */
    public function __construct(
        public int $betragCent,
        public string $konto,
        public string $gegenkonto,
        public \DateTimeImmutable $belegdatum,
        public string $belegfeld1,
        public string $buchungstext,
    ) {
    }
}
