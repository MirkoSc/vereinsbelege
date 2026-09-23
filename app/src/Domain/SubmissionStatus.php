<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of a `submission` row (docs/spec/02-datenmodell.md "Fachdaten").
 * Only one case exists yet (issue #24/M4-2): a submission is a write-once
 * record of what a visitor sent in, and nothing in this milestone changes it
 * afterwards. The inbox (M4-5) adds further states as it starts touching
 * these rows (e.g. marking one as spam-discarded).
 */
enum SubmissionStatus: string
{
    case Eingegangen = 'eingegangen';
}
