<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

/**
 * The outcome of ERechnungLeser/ERechnungPdf: why (befund) and, when read,
 * the invoice itself.
 */
final readonly class ERechnungErgebnis
{
    public function __construct(
        public ERechnungBefund $befund,
        public ?ERechnung $rechnung = null,
    ) {
    }

    public static function ohne(ERechnungBefund $befund): self
    {
        return new self($befund);
    }
}
