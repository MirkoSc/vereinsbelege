<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * The header line of a DATEV Buchungsstapel (issue #93, E-49): who the
 * batch is for (advisor and client number), how long a ledger account is,
 * which chart of accounts, and the period. Configuration and structure
 * only - no business data, no name of a person.
 */
final readonly class DatevKopf
{
    /**
     * @param string $beraternummer 1001 to 9999999
     * @param string $mandantennummer 1 to 99999
     * @param int $sachkontenlaenge 4 to 8 digits
     * @param string $kontenrahmen two digits ("03", "04", "42", "49") or empty
     * @param \DateTimeImmutable $von first day, same calendar year as $bis
     */
    public function __construct(
        public string $beraternummer,
        public string $mandantennummer,
        public int $sachkontenlaenge,
        public string $kontenrahmen,
        public \DateTimeImmutable $von,
        public \DateTimeImmutable $bis,
        public \DateTimeImmutable $erzeugt,
        public string $bezeichnung = 'Vereinsbelege',
    ) {
    }
}
