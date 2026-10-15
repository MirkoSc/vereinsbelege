<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\InvoiceDirection;

/**
 * What one path of the archive says about its receipt (issue #77/M12-3):
 * the file name through App\Service\Archiv\DateinamenMuster, the folders
 * through App\Service\Archiv\ArchivPfad. Everything here is a suggestion -
 * it pre-fills the review page and a person confirms it.
 *
 * `ueberspringen` is set (German, why) when the entry is no receipt at all:
 * the export's `_Originale/` copies, `__MACOSX/`, `Thumbs.db` and the like.
 * `systemdatei` tells the import to count such a file without listing it in
 * the report - nobody needs to read about `.DS_Store`.
 */
final readonly class ArchivVorbelegung
{
    /**
     * @param list<string> $hinweise German, shown on the review page
     */
    public function __construct(
        public string $pfad,
        public ?string $lieferant = null,
        public bool $ohneLieferant = false,
        public ?\DateTimeImmutable $datum = null,
        public ?string $nr = null,
        public ?int $kategorieId = null,
        public InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
        public bool $richtungAusOrdner = false,
        public bool $kasse = false,
        public bool $wiedervorlage = false,
        public bool $musterPasst = false,
        public array $hinweise = [],
        public ?string $ueberspringen = null,
        public bool $systemdatei = false,
    ) {
    }

    /** Anything at all to pre-fill? */
    public function hatWerte(): bool
    {
        return $this->lieferant !== null || $this->datum !== null || $this->nr !== null
            || $this->kategorieId !== null || $this->kasse || $this->richtungAusOrdner;
    }
}
