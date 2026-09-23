<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a `document` row came from (docs/spec/02-datenmodell.md "Fachdaten").
 * `Einreichung` comes from the public submission (issue #24/M4-2), `Intern`
 * from the internal capture /app/belege/neu (issue #28/M4-6); the others
 * arrive with the archive import (M12) and structured e-invoice reading (M7).
 */
enum DocumentSource: string
{
    case Einreichung = 'einreichung';
    case Intern = 'intern';
    case Archiv = 'archiv';
    case ERechnung = 'erechnung';
}
