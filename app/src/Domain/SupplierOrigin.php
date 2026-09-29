<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a supplier came to exist (`supplier.created_via`, docs/spec/
 * 02-datenmodell.md "Fachdaten"). The page of M6-2 creates `manuell`; the
 * automatic resolution (M7-6) creates `ki` with `needs_review`, the archive
 * import `archiv`.
 */
enum SupplierOrigin: string
{
    case Ki = 'ki';
    case Manuell = 'manuell';
    case Archiv = 'archiv';

    public function label(): string
    {
        return match ($this) {
            self::Ki => 'von der KI angelegt',
            self::Manuell => 'manuell angelegt',
            self::Archiv => 'aus dem Archiv-Import',
        };
    }
}
