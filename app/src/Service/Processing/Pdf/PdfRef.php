<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/** An indirect reference (`12 0 R`), resolved by App\Service\Processing\Pdf\PdfDokument. */
final readonly class PdfRef
{
    public function __construct(public int $nummer)
    {
    }
}
