<?php

declare(strict_types=1);

namespace App\Service\Job;

/**
 * A session job type that runs in the background for a long time without
 * being a receipt in processing (issue #88: the archive OCR backfill,
 * App\Service\Worker\BestandsOcr, waits for the worker for hours or days).
 * JobRunner still claims and steps it, but leaves it out of the header
 * count "N Belege in Verarbeitung" - that count stays about receipts.
 */
interface HintergrundJob extends JobHandler
{
}
