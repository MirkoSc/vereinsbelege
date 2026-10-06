<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * A PDF name object (`/Font`), told apart from a string of the same bytes -
 * the operands of a content stream and the values of a dictionary need both.
 * `$wert` is without the leading slash, `#xx` escapes already resolved.
 */
final readonly class PdfName
{
    public function __construct(public string $wert)
    {
    }
}
