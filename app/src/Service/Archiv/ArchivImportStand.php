<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\ArchiveImportStatus;

/**
 * Where an archive import stands after a step (issue #77/M12-3) - what the
 * step route answers as JSON and public/js/archivimport.js reads.
 * `verarbeitet` and `gesamt` count ZIP entries, `belege` the documents
 * written so far. `meldung` explains an import that was cut off.
 */
final readonly class ArchivImportStand
{
    public function __construct(
        public ArchiveImportStatus $status,
        public int $verarbeitet,
        public int $gesamt,
        public int $belege,
        public ?string $meldung = null,
    ) {
    }

    /**
     * @return array{status: string, verarbeitet: int, gesamt: int, belege: int, meldung: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'verarbeitet' => $this->verarbeitet,
            'gesamt' => $this->gesamt,
            'belege' => $this->belege,
            'meldung' => $this->meldung,
        ];
    }
}
