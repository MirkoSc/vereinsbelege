<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a `document` row came from (docs/spec/02-datenmodell.md "Fachdaten").
 * Only `Einreichung` is produced yet (issue #24/M4-2); the others arrive with
 * the internal capture page (M4-6), the archive import (M12) and structured
 * e-invoice reading (M7).
 */
enum DocumentSource: string
{
    case Einreichung = 'einreichung';
    case Intern = 'intern';
    case Archiv = 'archiv';
    case ERechnung = 'erechnung';
}
