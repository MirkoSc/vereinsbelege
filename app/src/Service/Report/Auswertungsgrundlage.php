<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The decrypted input of a report: the lines of one basis and period, plus
 * what had to be left out (shown on the page, never guessed around).
 */
final readonly class Auswertungsgrundlage
{
    /**
     * @param list<Posten> $posten
     */
    public function __construct(
        public Grundlage $grundlage,
        public Zeitraum $zeitraum,
        public array $posten,
        /** Rows that could not be decrypted (sealed to an older vault, damaged). */
        public int $unlesbar = 0,
        /** Rows in another currency than EUR - not summed with euros. */
        public int $fremdwaehrung = 0,
    ) {
    }
}
