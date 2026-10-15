<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * One file of the preview (issue #77/M12-3): what its path says and what
 * would become of the supplier - `vorhanden` (found by name or alias), `neu`
 * (created by the import), `mehrdeutig` (several have that name - none is
 * assigned) or null when the name gives none.
 */
final readonly class ArchivVorschauZeile
{
    public function __construct(
        public ArchivVorbelegung $vorbelegung,
        public ?string $lieferantStatus,
        public ?string $kategorie,
    ) {
    }
}
