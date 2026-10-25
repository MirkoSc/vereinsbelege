<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\DocumentStatus;

/**
 * A receipt allocated to a booking of the Kassenprüfung (issue #74), as
 * far as the report names it - decrypted, only in a session with an
 * unlocked vault, never logged or put into a URL beyond its document id.
 */
final readonly class Pruefbeleg
{
    public function __construct(
        public int $dokumentId,
        public \DateTimeImmutable $datum,
        public string $lieferant,
        public string $nummer,
        public DocumentStatus $status,
    ) {
    }

    /** "Beleg 12 · Lieferant · Nr. R-1 · geprüft" - for a table cell or a CSV field. */
    public function bezeichnung(): string
    {
        $teile = ['Beleg ' . $this->dokumentId, $this->datum->format('d.m.Y')];
        if ($this->lieferant !== '') {
            $teile[] = $this->lieferant;
        }
        if ($this->nummer !== '') {
            $teile[] = 'Nr. ' . $this->nummer;
        }
        $teile[] = $this->status->bezeichnung();

        return implode(' · ', $teile);
    }
}
