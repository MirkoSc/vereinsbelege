<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * A bare keyword of the PDF syntax that is not a value: `obj`, `stream`,
 * `R` - and in a content stream every operator (`Tj`, `BT`, `cm`).
 */
final readonly class PdfKeyword
{
    public function __construct(public string $wert)
    {
    }
}
