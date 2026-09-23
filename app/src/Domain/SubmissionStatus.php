<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `submission` row (docs/spec/02-datenmodell.md "Fachdaten").
 * Only one case exists (issue #24/M4-2): a submission is a write-once record
 * of what a visitor sent in. The inbox (issue #27/M4-5) decides on the
 * document it produced - accepted, rejected or put on Wiedervorlage is
 * `document.status` (App\Domain\DocumentStatus), not this.
 */
enum SubmissionStatus: string
{
    case Eingegangen = 'eingegangen';
}
