<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\BlobStorage;

/**
 * What the storage admin page shows and what one step of the switch chain
 * answers (M2-5): where the blobs currently lie, how many still have to
 * move, and how the integrity check is doing.
 *
 * The progress of the move is not stored anywhere - it is counted from
 * `file_blob` on every request. That is what makes the chain resumable after
 * a closed tab or a timeout: there is no half-written state to repair.
 *
 * Numbers only. `bestand` holds counts and byte sums of encrypted files,
 * `beschaedigt` row ids - nothing in here describes a receipt.
 */
final readonly class StorageState
{
    /**
     * @param array<string, array{anzahl: int, bytes: int, entwuerfe: int}> $bestand per backend
     * @param list<int> $misslungen ids that could not be moved in this request
     */
    public function __construct(
        public BlobStorage $ziel,
        public array $bestand,
        public int $offen,
        public int $gesamt,
        public int $entwuerfe,
        public StorageCheck $pruefung,
        public int $verschoben = 0,
        public int $bytes = 0,
        public array $misslungen = [],
        public ?string $meldung = null,
    ) {
    }

    /**
     * The same snapshot plus what one `verschieben` request achieved.
     *
     * @param list<int> $misslungen
     */
    public function mitFortschritt(int $verschoben, int $bytes, array $misslungen): self
    {
        return new self(
            ziel: $this->ziel,
            bestand: $this->bestand,
            offen: $this->offen,
            gesamt: $this->gesamt,
            entwuerfe: $this->entwuerfe,
            pruefung: $this->pruefung,
            verschoben: $verschoben,
            bytes: $bytes,
            misslungen: $misslungen,
            meldung: $this->meldung,
        );
    }

    /** Nothing left to move - everything finished lies on the target. */
    public function fertig(): bool
    {
        return $this->offen === 0;
    }

    /**
     * @return array{anzahl: int, bytes: int, entwuerfe: int}
     */
    public function bestandFuer(BlobStorage $storage): array
    {
        return $this->bestand[$storage->value] ?? ['anzahl' => 0, 'bytes' => 0, 'entwuerfe' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ziel' => $this->ziel->value,
            'bestand' => $this->bestand,
            'offen' => $this->offen,
            'gesamt' => $this->gesamt,
            'entwuerfe' => $this->entwuerfe,
            'verschoben' => $this->verschoben,
            'bytes' => $this->bytes,
            'misslungen' => $this->misslungen,
            'fertig' => $this->fertig(),
            'meldung' => $this->meldung,
            'pruefung' => $this->pruefung->toArray(),
        ];
    }
}
