<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Marker for `job.last_error` (issue #30/M4-8, docs/spec/06-betrieb.md
 * section 4): a `render_pages` job the browser could not get through,
 * whether pdf.js itself rejected the file (corrupt PDF) or no browser ever
 * managed a single page (App\Service\Document\PdfRasterung::MAX_VERSUCHE).
 * Never thrown; only its class name is stored (App\Repository\
 * JobRepository::fail()).
 */
final class PdfRasterungFehlgeschlagen extends \RuntimeException
{
}
