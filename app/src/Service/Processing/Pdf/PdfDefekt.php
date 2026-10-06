<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * The PDF does not follow the syntax this reader understands, or exceeds
 * one of its limits. Internal to the text layer reader: App\Service\
 * Processing\Pdf\PdfTextlayer turns it into a result without text (the
 * document then goes the page image route, docs/spec/03-erfassung-und-ki.md
 * section 3), it never reaches a job. Messages are technical only - never
 * quoting content of the file (CLAUDE.md section 4).
 */
final class PdfDefekt extends \RuntimeException
{
}
