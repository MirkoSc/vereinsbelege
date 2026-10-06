<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

/**
 * One line of the balance check in the import preview (M9-4, issue #62):
 * "balance before + bookings = balance after", as the file (or the
 * application) states each part. Amounts in signed cents. Shown in the
 * session only, never stored or logged - the differences are vault data.
 */
final readonly class Saldenpruefpunkt
{
    public function __construct(
        public string $bezeichnung,
        public int $anfangCent,
        public int $umsaetzeCent,
        public int $schlussCent,
        public string $waehrung,
        public bool $waehrungStimmt = true,
    ) {
    }

    public function differenzCent(): int
    {
        return $this->anfangCent + $this->umsaetzeCent - $this->schlussCent;
    }

    public function stimmt(): bool
    {
        return $this->waehrungStimmt && $this->differenzCent() === 0;
    }
}
