<?php

declare(strict_types=1);

namespace App\Service\Processing;

/**
 * Something about a page's image or the PDF built from it did not work out
 * (docs/spec/03-erfassung-und-ki.md section 3). The message names the
 * technical reason only - never a file name, a page count tied to a
 * specific document, or anything else that quotes business data
 * (CLAUDE.md section 4).
 */
final class ProcessingException extends \RuntimeException
{
}
