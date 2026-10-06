<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\BankImportStatus;

/**
 * Where the step chain of an import stands after one step (M9-4, issue
 * #62) - the JSON answer of `POST /app/konten/import/{id}/schritt`. Counts
 * only.
 */
final readonly class ImportStand
{
    public function __construct(
        public BankImportStatus $status,
        public int $verarbeitet,
        public int $gesamt,
    ) {
    }

    /**
     * @return array{status: string, verarbeitet: int, gesamt: int}
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'verarbeitet' => $this->verarbeitet, 'gesamt' => $this->gesamt];
    }
}
