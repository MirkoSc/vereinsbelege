<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * The PDF exceeds a limit of the text layer reader (App\Service\Processing\
 * Pdf\PdfTextlayer). Deliberately not a PdfDefekt: the places that skip a
 * single damaged stream and carry on must not carry on past a limit - it
 * ends the whole file.
 */
final class PdfZuGross extends \RuntimeException
{
}
