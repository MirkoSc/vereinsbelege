<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\ArchiveImportRecord;

/**
 * The preview of an uploaded archive before anything is written (issue
 * #77/M12-3): counts over all entries and the first rows in detail, worked
 * out from the ZIP with the pattern the page asked for. `fehler` is set
 * when the ZIP cannot be read (any more) - then nothing else is.
 */
final readonly class ArchivVorschau
{
    /**
     * @param array{dateien: int, ordner: int, systemdateien: int, uebersprungen: int, ohne_vorbelegung: int, kasse: int, wiedervorlage: int, lieferanten_neu: int, lieferanten_mehrdeutig: int} $zaehler
     * @param list<ArchivVorschauZeile> $zeilen
     */
    public function __construct(
        public ArchiveImportRecord $import,
        public string $muster,
        public ?string $musterFehler,
        public array $zaehler,
        public array $zeilen,
        public ?string $fehler = null,
    ) {
    }
}
